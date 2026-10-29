<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Anwendung;
use App\Config\Config;
use App\Config\Paths;
use App\Dienste;
use App\Domain\Konto;
use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Service\Migration\Migrator;
use App\Support\FileLogger;
use App\Support\Version;
use App\View\Stylesheets;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * Feature tests against the application's own wiring (App\Anwendung): every
 * route file, the real login guard, the real CSRF check, the real templates.
 *
 * als(Rolle) logs in a made-up account of that role without the real login
 * - until issue #6 builds the login, and afterwards still the short way for
 * tests that are not about the login (docs/spec/05-entwicklung.md).
 */
abstract class AppTestCase extends TestCase
{
    protected Dienste $dienste;

    protected Kernel $kernel;

    protected TestKonten $konten;

    private static int $naechsteId = 100;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->konten = new TestKonten();
        $this->dienste = self::baueDienste($this->konten, $this->pdoFabrik(), $this->paths());
        $this->kernel = Anwendung::kernel($this->dienste);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * The database for tests that need one; null = none (a test that
     * reaches for it fails loudly).
     *
     * @return (\Closure(): \PDO)|null
     */
    protected function pdoFabrik(): ?\Closure
    {
        return null;
    }

    /**
     * A migrated, empty database for a feature test whose pages read from
     * one (the settings page does, since the section Mail exists). Use as
     * `return $this->mitDatenbank();` in pdoFabrik().
     *
     * @return \Closure(): \PDO
     */
    protected function mitDatenbank(): \Closure
    {
        $pdo = DatabaseTestCase::leereDatenbank();
        if ($pdo === null) {
            self::markTestSkipped('Test database not available.');
        }
        new Migrator($pdo, dirname(__DIR__, 2) . '/migrations')->migrate();

        return static fn(): \PDO => $pdo;
    }

    /**
     * Other paths than the repository's own, for a test that writes below
     * shared/ (see Feature\VereinTest); null = the repository's.
     */
    protected function paths(): ?Paths
    {
        return null;
    }

    public static function baueDienste(TestKonten $konten, ?\Closure $pdoFabrik = null, ?Paths $paths = null): Dienste
    {
        $paths ??= new Paths(dirname(__DIR__, 2));
        $version = new Version('0.0.0-test');

        return new Dienste(
            paths: $paths,
            config: Config::fromArray(DatabaseTestCase::configData()),
            version: $version,
            view: new View($paths->viewsDir(), $version->value, Stylesheets::aus($paths->publicDir())),
            session: new Session(),
            logger: new FileLogger(sys_get_temp_dir() . '/mv-test-' . getmypid() . '/app.log'),
            pdoFabrik: $pdoFabrik ?? static fn(): never => throw new \LogicException('This test has no database.'),
            kontoquelle: $konten,
        );
    }

    /**
     * Logs in a made-up account of this role, without the real login.
     */
    protected function als(Rolle $rolle, bool $barrierefrei = false): Konto
    {
        $konto = new Konto(
            id: ++self::$naechsteId,
            name: 'Testzugang ' . $rolle->bezeichnung(),
            rolle: $rolle,
            barrierefrei: $barrierefrei,
        );
        $this->konten->anmelden($konto);

        return $konto;
    }

    protected function abmelden(): void
    {
        $_SESSION = [];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    protected function get(string $pfad, array $query = [], array $headers = []): Response
    {
        return $this->aufrufen(new Request(HttpMethod::Get, $pfad, query: $query, headers: $headers));
    }

    /**
     * A form POST - with the session's CSRF token unless told otherwise.
     *
     * @param array<string, string> $felder
     */
    protected function post(string $pfad, array $felder = [], bool $mitToken = true): Response
    {
        if ($mitToken) {
            $session = new Session();
            $session->start();
            $felder['_csrf'] = $session->csrfToken();
        }

        return $this->aufrufen(new Request(HttpMethod::Post, $pfad, post: $felder));
    }

    protected function aufrufen(Request $request): Response
    {
        $antwort = $this->kernel->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    protected static function assertWeiterleitungNach(string $ziel, Response $antwort): void
    {
        self::assertSame(302, $antwort->status, 'expected a redirect');
        self::assertSame($ziel, $antwort->headers['Location'] ?? null);
    }
}
