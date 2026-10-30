<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config\Paths;
use App\Domain\Konto;
use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Installer\ConfigWriter;
use App\Repository\SchluesselwechselRepository;
use App\Service\Crypto\Schluesselwechsel;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\TestBilder;

/**
 * The key change in the settings section System (issue #80) through the
 * whole application: only after the password is typed again, then the
 * fragment drives the chain on its own until the new key is shown once.
 */
final class SchluesselwechselSeiteTest extends AppTestCase
{
    private string $wurzel;

    private string $alterSchluessel;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function paths(): ?Paths
    {
        $this->wurzel = sys_get_temp_dir() . '/mv-schluessel-' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/shared/var', 0775, true);
        symlink(dirname(__DIR__, 2), $this->wurzel . '/current');
        $this->alterSchluessel = random_bytes(32);
        ConfigWriter::schreiben($this->wurzel . '/shared/config.php', ['host' => 'db', 'port' => 3306, 'name' => 'x', 'user' => 'x', 'password' => 'x'], base64_encode($this->alterSchluessel));

        return new Paths($this->wurzel . '/current');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // no waiting time and no backup in a test
        $this->dienste->dienst(Schluesselwechsel::class, fn(): Schluesselwechsel => new Schluesselwechsel(
            configDatei: $this->wurzel . '/shared/config.php',
            statusDir: $this->wurzel . '/shared/var',
            repo: new SchluesselwechselRepository($this->dienste->pdo()),
            audit: $this->dienste->audit(),
            backupLaeuft: static fn(): bool => false,
            gnadenfrist: 0,
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->wurzel . '/current');
        TestBilder::loeschen($this->wurzel);
        parent::tearDown();
    }

    public function testTheFragmentOffersTheChangeOnlyWithThePassword(): void
    {
        $this->alsEchterAdmin();

        $antwort = $this->htmx(HttpMethod::Get, '/admin/schluesselwechsel');

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Server-Schlüssel wechseln', $antwort->body);
        self::assertStringContainsString('name="passwort"', $antwort->body);
        self::assertStringContainsString('id="schluesselwechsel"', $this->get('/admin')->body, 'the section loads it');
    }

    public function testAWrongPasswordChangesNothing(): void
    {
        $this->alsEchterAdmin();

        $antwort = $this->htmx(HttpMethod::Post, '/admin/schluesselwechsel/start', ['passwort' => 'falsch', 'verstanden' => '1']);

        self::assertStringContainsString('Das Passwort stimmt nicht.', $antwort->body);
        self::assertSame([$this->alterSchluessel, null], ConfigWriter::schluesselLesen($this->wurzel . '/shared/config.php'));
    }

    public function testWithThePasswordTheChainRunsAndShowsTheNewKeyOnce(): void
    {
        $this->alsEchterAdmin();

        $antwort = $this->htmx(HttpMethod::Post, '/admin/schluesselwechsel/start', ['passwort' => Benutzerdaten::PASSWORT, 'verstanden' => '1']);
        self::assertStringContainsString('hx-post="/admin/schluesselwechsel/schritt"', $antwort->body, 'the fragment drives itself on');
        [$neu, $alt] = ConfigWriter::schluesselLesen($this->wurzel . '/shared/config.php');
        self::assertSame($this->alterSchluessel, $alt);

        for ($i = 0; $i < 20 && str_contains($antwort->body, 'hx-post="/admin/schluesselwechsel/schritt"'); $i++) {
            $antwort = $this->htmx(HttpMethod::Post, '/admin/schluesselwechsel/schritt');
        }

        self::assertStringContainsString('Der Server-Schlüssel ist gewechselt.', $antwort->body);
        self::assertStringContainsString(implode('</span> <span>', str_split(base64_encode($neu), 4)), $antwort->body);
        self::assertSame([$neu, null], ConfigWriter::schluesselLesen($this->wurzel . '/shared/config.php'));

        $nochmal = $this->htmx(HttpMethod::Get, '/admin/schluesselwechsel');
        self::assertStringNotContainsString(substr(base64_encode($neu), 0, 4) . '</span>', $nochmal->body, 'shown only once');
        self::assertStringContainsString('Zuletzt gewechselt am', $nochmal->body);
    }

    public function testWithoutHtmxTheRoutesLeadBackToTheSettings(): void
    {
        $this->alsEchterAdmin();

        self::assertWeiterleitungNach('/admin#system', $this->get('/admin/schluesselwechsel'));
    }

    public function testTheBoardCannotChangeTheKey(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->htmx(HttpMethod::Post, '/admin/schluesselwechsel/start', ['passwort' => 'x', 'verstanden' => '1'])->status);
        self::assertSame([$this->alterSchluessel, null], ConfigWriter::schluesselLesen($this->wurzel . '/shared/config.php'));
    }

    private function alsEchterAdmin(): void
    {
        $id = Benutzerdaten::anlegen($this->dienste->pdo(), rolle: Rolle::Admin);
        $this->konten->anmelden(new Konto(id: $id, name: 'Erika Beispiel', rolle: Rolle::Admin));
    }

    /**
     * @param array<string, string> $felder
     */
    private function htmx(HttpMethod $methode, string $pfad, array $felder = []): Response
    {
        $session = new Session();
        $session->start();

        return $this->aufrufen(new Request($methode, $pfad, post: $felder, headers: [
            'hx-request' => 'true',
            'x-csrf-token' => $session->csrfToken(),
        ]));
    }
}
