<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config\Paths;
use App\Domain\Rolle;
use App\Http\FileResponse;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestBilder;

/**
 * The settings section Verein and the logo through the whole application
 * (docs/spec/11-vereinsdaten.md). The uploads land in a temporary shared/:
 * the release root is a symbolic link to the repository, so views and
 * routes are the real ones.
 */
final class VereinTest extends AppTestCase
{
    private string $wurzel;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function paths(): ?Paths
    {
        $this->wurzel = sys_get_temp_dir() . '/mv-verein-' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/shared', 0775, true);
        symlink(dirname(__DIR__, 2), $this->wurzel . '/current');

        return new Paths($this->wurzel . '/current');
    }

    protected function tearDown(): void
    {
        if (isset($this->wurzel)) {
            @unlink($this->wurzel . '/current');
            TestBilder::loeschen($this->wurzel);
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    private static function formular(): array
    {
        return [
            'vereinsname' => 'Sportverein Beispielstadt e. V.',
            'kurzname' => 'SV Beispiel',
            'strasse' => 'Sportplatzweg 1',
            'plz' => '12345',
            'ort' => 'Beispielstadt',
            'email' => 'info@example.org',
            'iban' => 'de89 3704 0044 0532 0130 00',
            'glaeubiger_id' => 'DE98ZZZ09999999999',
            'mandatsdatum' => '2024-01-01',
        ];
    }

    private function hochladen(string $datei, int $fehler = UPLOAD_ERR_OK): Response
    {
        $session = new Session();
        $session->start();

        return $this->aufrufen(new Request(
            HttpMethod::Post,
            '/admin/verein/logo',
            post: ['_csrf' => $session->csrfToken()],
            files: ['logo' => ['name' => 'logo', 'tmp_name' => $datei, 'error' => $fehler, 'size' => 1]],
        ));
    }

    public function testTheAdminSeesTheSectionVorstandDoesNot(): void
    {
        $this->als(Rolle::Admin);
        self::assertStringContainsString('<h2 id="titel-verein">Verein</h2>', $this->get('/admin')->body);

        $this->als(Rolle::Vorstand);
        self::assertSame(403, $this->get('/admin')->status);
    }

    public function testVorstandCannotSave(): void
    {
        $this->als(Rolle::Vorstand);

        foreach (['/admin/verein', '/admin/verein/verbaende', '/admin/verein/logo/entfernen'] as $pfad) {
            self::assertSame(403, $this->post($pfad, self::formular())->status, $pfad);
        }
        self::assertSame(403, $this->hochladen(TestBilder::png($this->wurzel))->status);
        self::assertSame('0', (string) $this->dienste->pdo()->query("SELECT COUNT(*) FROM setting WHERE name IN ('verein', 'logo_version')")->fetchColumn());
    }

    public function testSavedClubDataComesBackInTheForm(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/verein', self::formular());

        self::assertWeiterleitungNach('/admin#verein', $antwort);
        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('value="Sportverein Beispielstadt e. V."', $seite);
        self::assertStringContainsString('value="DE89370400440532013000"', $seite);
        self::assertStringContainsString('Die Vereinsdaten sind gespeichert.', $seite);
    }

    public function testAnInvalidIbanIsSavedWithAHint(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/verein', ['iban' => 'DE00 1234'] + self::formular());

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Die IBAN ist nicht gültig', $seite);
        self::assertStringContainsString('value="DE001234"', $seite);
    }

    public function testAssociationsCanBeAddedChangedSortedAndDeleted(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/verein/verbaende', ['verband' => [
            ['id' => '', 'name' => 'BFV', 'vereinsnummer' => '99', 'bemerkung' => '', 'position' => '2'],
            ['id' => '', 'name' => 'BLSV', 'vereinsnummer' => '12345', 'bemerkung' => 'Sport', 'position' => '1'],
            ['id' => '', 'name' => '', 'vereinsnummer' => '', 'bemerkung' => '', 'position' => '3'],
        ]]);
        $seite = $this->get('/admin')->body;
        self::assertLessThan(strpos($seite, 'value="BFV"'), strpos($seite, 'value="BLSV"'), 'sorted by position');
        self::assertStringContainsString('value="12345"', $seite);

        $zeilen = $this->dienste->pdo()->query('SELECT id, name FROM verband ORDER BY sortierung')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $blsv = (string) array_search('BLSV', $zeilen, true);
        $this->post('/admin/verein/verbaende', ['verband' => [
            ['id' => $blsv, 'name' => 'BLSV', 'vereinsnummer' => '777', 'bemerkung' => '', 'position' => '1'],
            ['id' => (string) array_search('BFV', $zeilen, true), 'name' => '', 'vereinsnummer' => '', 'bemerkung' => '', 'position' => '2'],
        ]]);

        $rest = $this->dienste->pdo()->query('SELECT id, name, vereinsnummer FROM verband')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['id' => (int) $blsv, 'name' => 'BLSV', 'vereinsnummer' => '777']], $rest);
    }

    public function testWithoutALogoThePlaceholderIsDelivered(): void
    {
        $antwort = $this->kernel->handle(new Request(HttpMethod::Get, '/logo/kopf'));

        self::assertInstanceOf(FileResponse::class, $antwort);
        self::assertSame('image/svg+xml', $antwort->headers['Content-Type']);
        self::assertStringEndsWith('/public/img/vereinslogo-platzhalter.svg', $antwort->filePath);
    }

    public function testAnUnknownSizeIsNotFound(): void
    {
        self::assertSame(404, $this->get('/logo/riesig')->status);
    }

    public function testAnUploadedPngIsDeliveredShownAndUsedAsIcon(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->hochladen(TestBilder::png($this->wurzel));

        self::assertWeiterleitungNach('/admin#verein', $antwort);
        $this->abmelden();
        foreach (['kopf', 'anmeldung', 'favicon', 'apple'] as $groesse) {
            $bild = $this->kernel->handle(new Request(HttpMethod::Get, '/logo/' . $groesse, query: ['v' => '1']));
            self::assertInstanceOf(FileResponse::class, $bild, $groesse);
            self::assertSame('image/png', $bild->headers['Content-Type']);
            self::assertStringContainsString('immutable', $bild->headers['Cache-Control']);
            self::assertSame(IMAGETYPE_PNG, getimagesize($bild->filePath)[2]);
        }
        // Without ?v= the same URL must be revalidated.
        $ohne = $this->kernel->handle(new Request(HttpMethod::Get, '/logo/kopf'));
        self::assertInstanceOf(FileResponse::class, $ohne);
        self::assertSame('no-cache', $ohne->headers['Cache-Control']);

        $login = $this->get('/anmelden')->body;
        self::assertMatchesRegularExpression('#<img class="anmeldung-logo" src="/logo/anmeldung\?v=\d+"#', $login);
        self::assertMatchesRegularExpression('#<link rel="icon" href="/logo/favicon\?v=\d+" type="image/png">#', $login);
        self::assertMatchesRegularExpression('#<link rel="apple-touch-icon" href="/logo/apple\?v=\d+">#', $login);
        $this->als(Rolle::Vorstand);
        self::assertMatchesRegularExpression('#<img class="marke-logo" src="/logo/kopf\?v=\d+"#', $this->get('/app/mitglieder')->body);
    }

    public function testAnSvgIsRefusedWithAMessage(): void
    {
        $this->als(Rolle::Admin);

        $this->hochladen(TestBilder::svg($this->wurzel));

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('PNG oder JPEG', $seite);
        self::assertStringContainsString('src="/img/vereinslogo-platzhalter.svg"', $seite);
        self::assertSame('0', (string) $this->dienste->pdo()->query("SELECT COUNT(*) FROM setting WHERE name = 'logo_version'")->fetchColumn());
    }

    public function testMissingFileAndUploadErrorsAreReported(): void
    {
        $this->als(Rolle::Admin);

        $this->hochladen('', UPLOAD_ERR_NO_FILE);
        self::assertStringContainsString('Bitte eine Bilddatei auswählen.', $this->get('/admin')->body);

        $this->hochladen('', UPLOAD_ERR_INI_SIZE);
        self::assertStringContainsString('höchstens 5 MB', $this->get('/admin')->body);
    }

    public function testRemovingTheLogoBringsBackThePlaceholder(): void
    {
        $this->als(Rolle::Admin);
        $this->hochladen(TestBilder::jpeg($this->wurzel));

        $this->post('/admin/verein/logo/entfernen');

        $login = $this->get('/anmelden')->body;
        self::assertStringContainsString('src="/img/vereinslogo-platzhalter.svg?v=0.0.0-test"', $login);
        self::assertStringNotContainsString('apple-touch-icon', $login);
    }
}
