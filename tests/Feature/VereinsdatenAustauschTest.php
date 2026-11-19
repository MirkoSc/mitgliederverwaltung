<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config\Paths;
use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Service\Verein\LogoService;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestBilder;

/**
 * Export and import of the club data through the whole application
 * (docs/spec/11-vereinsdaten.md section 5).
 */
final class VereinsdatenAustauschTest extends AppTestCase
{
    private string $wurzel;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function paths(): ?Paths
    {
        $this->wurzel = sys_get_temp_dir() . '/mv-austausch-' . bin2hex(random_bytes(4));
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

    private function pdo(): \PDO
    {
        return $this->dienste->pdo();
    }

    /** Club data, two associations and a logo, as the admin made them. */
    private function einrichten(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/verein', [
            'vereinsname' => 'Sportverein Beispielstadt e. V.', 'kurzname' => 'SV Beispiel!', 'ort' => 'Beispielstadt',
            'iban' => 'DE89 3704 0044 0532 0130 00', 'glaeubiger_id' => 'DE98ZZZ09999999999', 'mandatsdatum' => '2024-01-01',
        ]);
        $this->post('/admin/verein/verbaende', ['verband' => [
            ['id' => '', 'name' => 'BFV', 'vereinsnummer' => '99', 'bemerkung' => '', 'position' => '2'],
            ['id' => '', 'name' => 'BLSV', 'vereinsnummer' => '12345', 'bemerkung' => 'Sport', 'position' => '1'],
        ]]);
        $this->hochladen(TestBilder::png($this->wurzel));
    }

    private function hochladen(string $datei): void
    {
        $session = new Session();
        $session->start();
        $this->aufrufen(new Request(
            HttpMethod::Post,
            '/admin/verein/logo',
            post: ['_csrf' => $session->csrfToken()],
            files: ['logo' => ['name' => 'logo', 'tmp_name' => $datei, 'error' => UPLOAD_ERR_OK, 'size' => 1]],
        ));
    }

    /** @param array<string, string> $felder */
    private function importieren(string $inhalt, array $felder = ['ersetzen' => '1'], string $name = 'vereinsdaten.json', bool $mitToken = true): Response
    {
        $datei = $this->wurzel . '/import-' . bin2hex(random_bytes(3)) . '.json';
        file_put_contents($datei, $inhalt);
        $session = new Session();
        $session->start();
        if ($mitToken) {
            $felder['_csrf'] = $session->csrfToken();
        }

        return $this->aufrufen(new Request(
            HttpMethod::Post,
            '/admin/verein/import',
            post: $felder,
            files: ['datei' => ['name' => $name, 'tmp_name' => $datei, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)]],
        ));
    }

    /** @return array<string, mixed> */
    private function exportiert(): array
    {
        $antwort = $this->get('/admin/verein/export');
        self::assertSame(200, $antwort->status);

        return json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** What a restart of the installation leaves: nothing of the club data. */
    private function leeren(): void
    {
        $this->post('/admin/verein/logo/entfernen');
        $this->pdo()->exec("DELETE FROM setting WHERE name = 'verein'");
        $this->pdo()->exec('DELETE FROM verband');
    }

    /** @return array{verein: array<string, string>, verbaende: list<array<string, mixed>>, logo: array<string, string>} */
    private function zustand(): array
    {
        $logo = [];
        foreach (new LogoService($this->dienste->paths, $this->dienste->einstellungen())->alsBytes() as $groesse => $bytes) {
            $logo[$groesse] = md5($bytes);
        }

        return [
            'verein' => $this->dienste->einstellungen()->get('verein') ?? [],
            'verbaende' => $this->pdo()->query('SELECT name, vereinsnummer, bemerkung FROM verband ORDER BY sortierung')->fetchAll(\PDO::FETCH_ASSOC),
            'logo' => $logo,
        ];
    }

    private function meldung(): string
    {
        return $this->get('/admin')->body;
    }

    public function testTheExportIsADownloadNamedAfterTheShortName(): void
    {
        $this->einrichten();

        $antwort = $this->get('/admin/verein/export');

        self::assertSame(200, $antwort->status);
        self::assertSame('no-store', $antwort->headers['Cache-Control']);
        self::assertSame('application/json; charset=utf-8', $antwort->headers['Content-Type']);
        self::assertMatchesRegularExpression('/^attachment; filename="vereinsdaten-sv-beispiel-\d{4}-\d{2}-\d{2}\.json"$/', $antwort->headers['Content-Disposition']);
        $datei = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('mitgliederverwaltung-vereinsdaten', $datei['format']);
        self::assertSame(1, $datei['version']);
        self::assertSame('0.0.0-test', $datei['app_version']);
        self::assertSame(['BLSV', 'BFV'], array_column($datei['verbaende'], 'name'));
        self::assertSame('DE89370400440532013000', $datei['verein']['iban']);
        self::assertSame(['kopf', 'anmeldung', 'favicon', 'apple', 'icon192', 'icon512', 'maskable'], array_keys($datei['logo']));
        self::assertStringNotContainsString("\n", $antwort->body, 'compact');
        self::assertSame('1', (string) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'verein.exportiert'")->fetchColumn());
    }

    public function testWithoutShortNameOrLogoTheFileHasNoShortNameAndNoLogo(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->get('/admin/verein/export');

        self::assertMatchesRegularExpression('/filename="vereinsdaten-\d{4}-\d{2}-\d{2}\.json"/', $antwort->headers['Content-Disposition']);
        $datei = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($datei['logo']);
        self::assertSame([], $datei['verbaende']);
    }

    public function testTheFileHoldsNoSecrets(): void
    {
        $this->einrichten();
        $this->dienste->einstellungen()->set('mail', ['smtp_passwort' => 'geheim-123', 'host' => 'smtp.example.org']);

        $inhalt = $this->get('/admin/verein/export')->body;

        foreach (['geheim-123', 'smtp', 'server_key', 'passwort', 'schluessel'] as $nicht) {
            self::assertStringNotContainsStringIgnoringCase($nicht, $inhalt, $nicht);
        }
        self::assertSame(['format', 'version', 'app_version', 'erzeugt', 'verein', 'verbaende', 'logo'], array_keys(json_decode($inhalt, true)));
    }

    public function testExportAndImportIntoAnEmptyInstallationGiveTheSameData(): void
    {
        $this->einrichten();
        $vorher = $this->zustand();
        $datei = $this->get('/admin/verein/export')->body;
        $this->leeren();
        self::assertSame([], $this->zustand()['logo']);

        $antwort = $this->importieren($datei);

        self::assertWeiterleitungNach('/admin#verein', $antwort);
        self::assertStringContainsString('Die Vereinsdaten sind importiert.', $this->meldung());
        self::assertSame($vorher, $this->zustand());
        self::assertSame(['BLSV', 'BFV'], array_column($this->zustand()['verbaende'], 'name'));
        self::assertNotNull(new LogoService($this->dienste->paths, $this->dienste->einstellungen())->version());
        self::assertSame('1', (string) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'verein.importiert'")->fetchColumn());
        self::assertSame('{}', (string) $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'verein.importiert'")->fetchColumn(), 'no contents');
    }

    public function testImportReplacesEverythingInsteadOfMerging(): void
    {
        $this->einrichten();
        $datei = json_encode([
            'format' => 'mitgliederverwaltung-vereinsdaten', 'version' => 1,
            'verein' => ['vereinsname' => 'Neuer Verein', 'unbekannt' => 'wird ignoriert'],
            'verbaende' => [['name' => 'Kegelverband', 'vereinsnummer' => '7']],
        ], JSON_THROW_ON_ERROR);

        $this->importieren($datei);

        $zustand = $this->zustand();
        self::assertSame('Neuer Verein', $zustand['verein']['vereinsname']);
        self::assertSame('', $zustand['verein']['iban'], 'a missing field becomes empty');
        self::assertSame('', $zustand['verein']['ort']);
        self::assertArrayNotHasKey('unbekannt', $zustand['verein']);
        self::assertSame([['name' => 'Kegelverband', 'vereinsnummer' => '7', 'bemerkung' => '']], $zustand['verbaende'], 'BLSV and BFV are gone');
        self::assertSame([], $zustand['logo'], 'a file without a logo removes the logo');
        self::assertStringContainsString('src="/img/vereinslogo-platzhalter.svg"', $this->meldung());
    }

    public function testTheSameHintsAsInTheFormAreGiven(): void
    {
        $this->als(Rolle::Admin);
        $datei = json_encode([
            'format' => 'mitgliederverwaltung-vereinsdaten', 'version' => 1,
            'verein' => ['iban' => 'de00 1234', 'bic' => ' abcd de ff', 'freistellungsdatum' => '31.02.2024'],
        ], JSON_THROW_ON_ERROR);

        $this->importieren($datei);

        $seite = $this->meldung();
        self::assertStringContainsString('Die IBAN ist nicht gültig', $seite);
        self::assertStringContainsString('Datum des letzten Freistellungsbescheids: Das Datum war nicht gültig', $seite);
        self::assertSame('DE001234', $this->zustand()['verein']['iban']);
        self::assertSame('ABCDDEFF', $this->zustand()['verein']['bic']);
        self::assertSame('', $this->zustand()['verein']['freistellungsdatum']);
    }

    /** @return array<string, array{string, string}> */
    public static function ungueltigeDateien(): array
    {
        $kopf = '"format":"mitgliederverwaltung-vereinsdaten"';

        return [
            'no json' => ['das ist kein json', 'keine Datei mit Vereinsdaten'],
            'wrong id' => ['{"format":"etwas-anderes","version":1}', 'keine Datei mit Vereinsdaten'],
            'json list' => ['[1,2]', 'keine Datei mit Vereinsdaten'],
            'newer version' => ['{' . $kopf . ',"version":2,"verein":{}}', 'neueren Version'],
            'no version' => ['{' . $kopf . ',"verein":{}}', 'Formatversion'],
            'field not text' => ['{' . $kopf . ',"version":1,"verein":{"iban":123}}', 'IBAN'],
            'association not text' => ['{' . $kopf . ',"version":1,"verein":{},"verbaende":[{"name":["x"]}]}', 'Verband'],
            'associations no list' => ['{' . $kopf . ',"version":1,"verein":{},"verbaende":"x"}', 'Verbände'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeDateien')]
    public function testAFileThatDoesNotPassChangesNothing(string $inhalt, string $meldung): void
    {
        $this->einrichten();
        $vorher = $this->zustand();

        $antwort = $this->importieren($inhalt);

        self::assertWeiterleitungNach('/admin#verein', $antwort);
        self::assertStringContainsString($meldung, $this->meldung());
        self::assertSame($vorher, $this->zustand());
        self::assertSame('0', (string) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'verein.importiert'")->fetchColumn());
    }

    public function testWithoutTheTickNothingIsChanged(): void
    {
        $this->einrichten();
        $vorher = $this->zustand();
        $datei = json_encode(['format' => 'mitgliederverwaltung-vereinsdaten', 'version' => 1, 'verein' => ['vereinsname' => 'X']], JSON_THROW_ON_ERROR);

        $this->importieren($datei, []);

        self::assertStringContainsString('vollständig ersetzt werden', $this->meldung());
        self::assertSame($vorher, $this->zustand());
    }

    public function testOnlyJsonFilesAreTaken(): void
    {
        $this->einrichten();
        $vorher = $this->zustand();

        $this->importieren($this->get('/admin/verein/export')->body, ['ersetzen' => '1'], 'vereinsdaten.txt');

        self::assertStringContainsString('Endung .json', $this->meldung());
        self::assertSame($vorher, $this->zustand());
    }

    /** @return array<string, array{\Closure(array<string, string>): array<string, mixed>}> */
    public static function kaputteLogos(): array
    {
        $png = static function (int $b, int $h): string {
            $bild = imagecreatetruecolor($b, $h);
            ob_start();
            imagepng($bild);

            return base64_encode((string) ob_get_clean());
        };

        return [
            'not a png' => [static fn(array $logo): array => ['kopf' => base64_encode('GIF89a nope')] + $logo],
            'not base64' => [static fn(array $logo): array => ['favicon' => '!!!'] + $logo],
            'too big' => [static fn(array $logo): array => ['kopf' => $png(500, 500)] + $logo],
            'a size is missing' => [static function (array $logo): array {
                unset($logo['apple']);

                return $logo;
            }],
        ];
    }

    /** @param \Closure(array<string, string>): array<string, mixed> $verderben */
    #[\PHPUnit\Framework\Attributes\DataProvider('kaputteLogos')]
    public function testABrokenLogoIsLeftOutTheDataIsImported(\Closure $verderben): void
    {
        $this->einrichten();
        $datei = $this->exportiert();
        $logoVorher = $this->zustand()['logo'];
        $datei['verein']['vereinsname'] = 'Anderer Name';
        $datei['logo'] = $verderben($datei['logo']);

        $this->importieren(json_encode($datei, JSON_THROW_ON_ERROR));

        $seite = $this->meldung();
        self::assertStringContainsString('Das Logo wurde nicht übernommen', $seite);
        self::assertSame('Anderer Name', $this->zustand()['verein']['vereinsname']);
        self::assertSame($logoVorher, $this->zustand()['logo'], 'the current logo stays');
    }

    public function testVerwaltungVorstandAndReadOnlyAreRefusedAndSeeNoButton(): void
    {
        $this->einrichten();
        $vorher = $this->zustand();
        $datei = json_encode(['format' => 'mitgliederverwaltung-vereinsdaten', 'version' => 1, 'verein' => ['vereinsname' => 'X']], JSON_THROW_ON_ERROR);

        $this->als(Rolle::Admin);
        $seite = $this->meldung();
        self::assertStringContainsString('href="/admin/verein/export"', $seite);
        self::assertStringContainsString('action="/admin/verein/import"', $seite);

        foreach ([Rolle::Verwaltung, Rolle::Vorstand, Rolle::Lesend] as $rolle) {
            $this->als($rolle);
            self::assertSame(403, $this->get('/admin/verein/export')->status, $rolle->value);
            self::assertSame(403, $this->importieren($datei)->status, $rolle->value);
        }

        $this->als(Rolle::Verwaltung);
        $seite = $this->get('/admin');
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('id="titel-verein"', $seite->body, 'the Verwaltung still edits the club data');
        self::assertStringNotContainsString('/admin/verein/export', $seite->body);
        self::assertStringNotContainsString('/admin/verein/import', $seite->body);
        self::assertSame($vorher, $this->zustand());
        self::assertSame('0', (string) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'verein.%'")->fetchColumn());
    }

    public function testAnImportWithoutACsrfTokenIsRefused(): void
    {
        $this->einrichten();
        $vorher = $this->zustand();
        $datei = json_encode(['format' => 'mitgliederverwaltung-vereinsdaten', 'version' => 1, 'verein' => ['vereinsname' => 'X']], JSON_THROW_ON_ERROR);

        $antwort = $this->importieren($datei, ['ersetzen' => '1'], mitToken: false);

        self::assertGreaterThanOrEqual(400, $antwort->status);
        self::assertSame($vorher, $this->zustand());
    }
}
