<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Tests\Fixtures\Listen\BeispielListe;
use App\Tests\Support\AppTestCase;

/**
 * The page "Listen" and its export, end to end with an example list that
 * the registry finds by its file name (docs/spec/09-listen-und-exporte.md,
 * "Pflicht-Tests"). The file is written for the test and removed afterwards,
 * as in ErweiterungsSeitenTest; .gitignore keeps `Testlauf*.php` out of
 * every commit.
 */
final class ListenSeiteTest extends AppTestCase
{
    private string $schluessel = '';

    /** @var list<string> */
    private array $angelegt = [];

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->schluessel = 'testlauf-' . bin2hex(random_bytes(4));
        $this->liste($this->schluessel, 'ListenSehen');
    }

    protected function tearDown(): void
    {
        foreach ($this->angelegt as $datei) {
            @unlink($datei);
        }
        parent::tearDown();
    }

    /** Writes a list of the example into app/src/Listen/ under this key. */
    private function liste(string $schluessel, string $recht): void
    {
        $klasse = 'Testlauf' . str_replace('-', '', ucwords(substr($schluessel, 9), '-')) . 'Liste';
        $datei = dirname(__DIR__, 2) . '/app/src/Listen/' . $klasse . '.php';
        file_put_contents($datei, <<<PHP
            <?php
            declare(strict_types=1);
            namespace App\\Listen;
            use App\\Domain\\Permission;
            final class {$klasse} extends \\App\\Tests\\Fixtures\\Listen\\BeispielListe
            {
                public function schluessel(): string { return '{$schluessel}'; }
                public function recht(): Permission { return Permission::{$recht}; }
            }
            PHP);
        $this->angelegt[] = $datei;
        register_shutdown_function(static function () use ($datei): void {
            if (is_file($datei)) {
                unlink($datei);
            }
        });
    }

    private function seite(array $query = []): string
    {
        $antwort = $this->get('/app/listen/' . $this->schluessel, $query);
        self::assertSame(200, $antwort->status);

        return $antwort->body;
    }

    /**
     * @param array<string, string> $felder
     */
    private function exportieren(array $felder, ?string $schluessel = null, bool $mitToken = true): StreamResponse
    {
        if ($mitToken) {
            $session = new Session();
            $session->start();
            $felder['_csrf'] = $session->csrfToken();
        }
        $antwort = $this->kernel->handle(new Request(HttpMethod::Post, '/app/listen/' . ($schluessel ?? $this->schluessel) . '/export', post: $felder, ip: '203.0.113.7'));
        self::assertInstanceOf(StreamResponse::class, $antwort, 'expected a download');

        return $antwort;
    }

    private static function inhalt(StreamResponse $antwort): string
    {
        return implode('', iterator_to_array($antwort->chunks, false));
    }

    public function testThePageShowsTheSelectionFiltersTableAndExport(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->seite();

        self::assertStringContainsString('<a href="/app/listen/' . $this->schluessel . '" aria-current="page">Beispielliste</a>', $seite);
        self::assertStringContainsString('Erfundene Personen für die Tests.', $seite);
        self::assertStringContainsString('Zwei Personen haben kein Geburtsdatum.', $seite, 'hints of the list above the table');
        self::assertStringContainsString('<select id="filter-gruppe" name="gruppe">', $seite);
        self::assertStringContainsString('<option value="b">Gruppe B</option>', $seite);
        self::assertStringContainsString('name="nur_gerade" value="1"', $seite);
        self::assertStringContainsString('name="jahr" value="2027"', $seite);
        self::assertStringContainsString('<th scope="col"><a href="/app/listen/' . $this->schluessel . '?sortierung=name" hx-get="', $seite);
        self::assertStringContainsString('>Name</a></th>', $seite);
        self::assertStringContainsString('<th scope="col" class="nebenspalte"><a ', $seite);
        self::assertStringContainsString('120 Einträge', $seite);
        self::assertStringContainsString('<a href="/app/listen/' . $this->schluessel . '?mitglied=1" hx-get="', $seite, 'a row opens the member on this page');
        self::assertStringNotContainsString('/app/mitglieder?mitglied=', $seite, 'no change to the members page any more');
        self::assertStringContainsString('<td data-label="Geburtstag"', $seite);
        self::assertStringContainsString('<span class="etikett etikett-info">rund</span>', $seite);
        self::assertStringContainsString('Die Datei enthält Mitgliederdaten im Klartext.', $seite);
        self::assertStringContainsString('<form class="formular listen-export-formular" method="post" action="/app/listen/' . $this->schluessel . '/export">', $seite);
        self::assertStringContainsString('<option value="xlsx">Excel-Datei (xlsx)</option>', $seite);
        self::assertStringContainsString('<option value="csv">CSV-Datei (csv)</option>', $seite);
    }

    public function testDatesAndAmountsAreShownTheGermanWay(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->seite();

        self::assertMatchesRegularExpression('/data-label="Geburtstag"[^>]*>\s*\d{2}\.\d{2}\.2000\s*</', $seite);
        self::assertMatchesRegularExpression('/data-label="Beitrag"[^>]*>\s*1,55\x{00A0}€\s*</u', $seite, 'Person 001: 155 cent');
    }

    public function testPagingShowsFiftyRowsAndTheSelectionOfPages(): void
    {
        $this->als(Rolle::Admin);

        $erste = $this->seite();
        $dritte = $this->seite(['seite' => '3']);

        self::assertStringContainsString('Person 050', $erste);
        self::assertStringNotContainsString('Person 051', $erste);
        self::assertStringContainsString('Seite 1 von 3, je Seite 50 Einträge', $erste);
        self::assertStringContainsString('Nächste Seite', $erste);
        self::assertStringNotContainsString('Vorherige Seite', $erste);
        self::assertStringContainsString('Person 120', $dritte);
        self::assertStringNotContainsString('Person 100<', $dritte);
        self::assertStringContainsString('Vorherige Seite', $dritte);
        self::assertStringNotContainsString('Nächste Seite', $dritte);
        self::assertStringContainsString('Seite 3 von 3', $this->seite(['seite' => '77']), 'a page beyond the end shows the last');
    }

    public function testFiltersNarrowTheTableAndStayInTheLinks(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->seite(['gruppe' => 'a', 'nur_gerade' => '1', 'jahr' => '2030']);

        self::assertStringContainsString('60 Einträge', $seite);
        self::assertStringContainsString('<option value="a" selected>Gruppe A</option>', $seite);
        self::assertStringContainsString('name="nur_gerade" value="1" checked', $seite);
        self::assertStringContainsString('name="jahr" value="2030"', $seite);
        self::assertStringContainsString('?gruppe=a&amp;nur_gerade=1&amp;jahr=2030&amp;sortierung=name', $seite, 'sort links keep the filters, the default ones stay out');
        self::assertStringContainsString('Jahr 2030', $seite);
        self::assertStringContainsString('gruppe=a&amp;nur_gerade=1&amp;jahr=2029', $seite, 'back leads to the period before');
        self::assertStringContainsString('gruppe=a&amp;nur_gerade=1&amp;jahr=2031', $seite, 'forward to the next');
    }

    public function testAnInvalidFilterValueFallsBackToTheDefault(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->seite(['gruppe' => 'x', 'jahr' => '<script>', 'nur_gerade' => 'vielleicht']);

        self::assertStringContainsString('120 Einträge', $seite);
        self::assertStringContainsString('name="jahr" value="2027"', $seite);
        self::assertStringNotContainsString('<script>', $seite);
    }

    public function testSortingIsMarkedAndToggles(): void
    {
        $this->als(Rolle::Admin);

        $auf = $this->seite(['sortierung' => 'name']);
        $ab = $this->seite(['sortierung' => 'name', 'richtung' => 'ab']);

        self::assertStringContainsString('<th scope="col" aria-sort="ascending">', $auf);
        self::assertStringContainsString('sortierung=name&amp;richtung=ab', $auf, 'the header link toggles to descending');
        self::assertStringContainsString('<th scope="col" aria-sort="descending">', $ab);
        self::assertStringContainsString('Person 120', explode('</tr>', $ab)[1], 'the first row of the page');
    }

    public function testNoRowsShowsTheEmptyStateInsteadOfATable(): void
    {
        $this->als(Rolle::Admin);
        $datei = dirname(__DIR__, 2) . '/app/src/Listen/Testlauf' . ucfirst(bin2hex(random_bytes(4))) . 'Liste.php';
        file_put_contents($datei, '<?php declare(strict_types=1); namespace App\Listen; use App\Domain\Konto; use App\Domain\Permission; final class ' . basename($datei, '.php')
            . ' extends \App\Tests\Fixtures\Listen\BeispielListe { public function schluessel(): string { return \'leer-' . substr($this->schluessel, 9) . '\'; }'
            . ' public function ergebnis(array $filter, Konto $konto): ListenErgebnis { return new ListenErgebnis([Spalte::text(\'name\', \'Name\')], []); } }');
        $this->angelegt[] = $datei;

        $seite = $this->get('/app/listen/leer-' . substr($this->schluessel, 9))->body;

        self::assertStringContainsString('Für diese Auswahl gibt es keine Einträge.', $seite);
        self::assertStringNotContainsString('<table', $seite);
        self::assertStringNotContainsString('Liste exportieren', $seite);
    }

    public function testTheBoardSeesNoFeeColumnAndTheAdminDoes(): void
    {
        $this->als(Rolle::Admin);
        self::assertStringContainsString('>Beitrag</a>', $this->seite());

        $this->als(Rolle::Vorstand);
        self::assertStringNotContainsString('>Beitrag</a>', $this->seite());
        self::assertStringNotContainsString('data-label="Beitrag"', $this->seite());
    }

    public function testAnAdminListIsNotFoundForTheBoard(): void
    {
        $this->liste('testlauf-' . ($kennung = bin2hex(random_bytes(4))), 'BeitraegeVerwalten');
        $schluessel = 'testlauf-' . $kennung;

        $this->als(Rolle::Admin);
        self::assertSame(200, $this->get('/app/listen/' . $schluessel)->status);

        $this->als(Rolle::Vorstand);
        self::assertSame(404, $this->get('/app/listen/' . $schluessel)->status);
        // the export is closed the same way - and writes no audit entry
        $vorher = $this->dienste->audit()->anzahl();
        self::assertSame(404, $this->post('/app/listen/' . $schluessel . '/export', ['format' => 'csv'])->status);
        self::assertSame($vorher, $this->dienste->audit()->anzahl());
    }

    public function testNobodyExportsWithoutLoginOrWithoutToken(): void
    {
        $this->abmelden();
        $ohneLogin = $this->post('/app/listen/' . $this->schluessel . '/export', ['format' => 'csv']);
        self::assertNotSame(200, $ohneLogin->status);

        $this->als(Rolle::Admin);
        self::assertSame(403, $this->post('/app/listen/' . $this->schluessel . '/export', ['format' => 'csv'], mitToken: false)->status);
        self::assertSame(0, $this->dienste->audit()->anzahl());
    }

    public function testCsvExportHasBomSemicolonsTheColumnsAndFiltersOfTheView(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->exportieren(['format' => 'csv', 'gruppe' => 'a', 'jahr' => '2030']);
        $csv = self::inhalt($antwort);

        self::assertSame('text/csv; charset=utf-8', $antwort->headers['Content-Type']);
        self::assertSame('attachment; filename="beispiel-2030-a.csv"', $antwort->headers['Content-Disposition']);
        self::assertStringStartsWith("\xEF\xBB\xBFName;Geburtstag;Alter;Gruppe;Beitrag\r\n", $csv);
        $zeilen = explode("\r\n", rtrim($csv, "\r\n"));
        self::assertCount(1 + 60, $zeilen, 'header plus the 60 rows of group A - all of them, not one page');
        self::assertSame('Person 002;03.03.2000;30;A;3,05', $zeilen[1]);
        self::assertStringContainsString('"Person 014; Z";', $csv, 'a semicolon in a field is quoted');
    }

    public function testXlsxExportOpensAndHoldsTheRowsWithRealDates(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->exportieren(['format' => 'xlsx']);
        $datei = tempnam(sys_get_temp_dir(), 'mv-liste-');
        self::assertIsString($datei);
        try {
            file_put_contents($datei, self::inhalt($antwort));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($datei, \ZipArchive::CHECKCONS));
            $blatt = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            self::assertInstanceOf(\SimpleXMLElement::class, $blatt);
            $blatt->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            self::assertCount(1 + BeispielListe::ANZAHL, $blatt->xpath('//m:row') ?: []);
            $erste = $blatt->xpath('//m:row[@r="2"]/m:c');
            self::assertIsArray($erste);
            self::assertSame('Person 001', (string) $erste[0]->is->t);
            self::assertSame('2', (string) $erste[1]['s'], 'the birthday is a date cell');
        } finally {
            @unlink($datei);
        }

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $antwort->headers['Content-Type']);
        self::assertSame('attachment; filename="beispiel-2027-alle.xlsx"', $antwort->headers['Content-Disposition']);
    }

    public function testTheExportFollowsTheSortOrderOfTheView(): void
    {
        $this->als(Rolle::Admin);

        $csv = self::inhalt($this->exportieren(['format' => 'csv', 'sortierung' => 'name', 'richtung' => 'ab']));

        self::assertStringStartsWith("\xEF\xBB\xBFName;", $csv);
        self::assertSame('Person 120', explode(';', explode("\r\n", $csv)[1])[0]);
    }

    public function testTheBoardsExportHasNoFeeColumn(): void
    {
        $this->als(Rolle::Vorstand);

        $csv = self::inhalt($this->exportieren(['format' => 'csv']));

        self::assertStringStartsWith("\xEF\xBB\xBFName;Geburtstag;Alter;Gruppe\r\n", $csv);
        self::assertStringNotContainsString('Beitrag', $csv);
    }

    public function testAnUnknownFormatGivesXlsx(): void
    {
        $this->als(Rolle::Admin);

        self::assertStringEndsWith('.xlsx"', $this->exportieren(['format' => 'pdf'])->headers['Content-Disposition']);
    }

    public function testEveryExportWritesAnAuditEntryWithoutMemberData(): void
    {
        $konto = $this->als(Rolle::Vorstand);
        $audit = $this->dienste->audit();

        self::inhalt($this->exportieren(['format' => 'csv', 'gruppe' => 'b']));
        self::inhalt($this->exportieren(['format' => 'xlsx']));

        self::assertSame(2, $audit->anzahl());
        [$neuer, $aelter] = $audit->neueste(2);
        self::assertSame('export.erzeugt', $aelter->action);
        self::assertSame($konto->id, $aelter->userId, 'who');
        self::assertNotSame('', $aelter->ts, 'when');
        self::assertSame('liste', $aelter->entity);
        self::assertSame($this->schluessel, $aelter->entityId, 'which list');
        self::assertSame(['format' => 'csv', 'zeilen' => 60], json_decode($aelter->details, true), 'how many rows');
        self::assertSame(['format' => 'xlsx', 'zeilen' => 120], json_decode($neuer->details, true));
        self::assertStringNotContainsString('Person', $aelter->details . $neuer->details);
        self::assertTrue($audit->pruefen()->intakt(), 'the chain holds');
    }

    public function testViewingAListWritesNoAuditEntry(): void
    {
        $this->als(Rolle::Admin);

        $this->seite();

        self::assertSame(0, $this->dienste->audit()->anzahl());
    }
}
