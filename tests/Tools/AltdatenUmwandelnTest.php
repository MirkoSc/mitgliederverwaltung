<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tests\Support\XlsxSchreiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tools\Altdaten\Kommandozeile;
use Tools\Altdaten\Umwandler;
use Tools\Altdaten\XlsxLeser;

require_once dirname(__DIR__, 2) . '/tools/altdaten-umwandeln.php';

/**
 * tools/altdaten-umwandeln.php against the INVENTED sample export in
 * tests/Fixtures/altdaten/ (docs/spec/10-import.md, mandatory tests).
 */
final class AltdatenUmwandelnTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/altdaten';

    /** @var array<string, array<string, string>>|null Mitgliedsnummer => row */
    private static ?array $zeilen = null;

    private static ?string $summary = null;

    private static string $csv = '';

    /** @return array<string, array<string, string>> */
    private static function zeilen(): array
    {
        if (self::$zeilen === null) {
            $ergebnis = (new Umwandler(new \DateTimeImmutable('2026-10-01')))->umwandeln(
                XlsxLeser::lesen(self::FIXTURES . '/mitglieder.xlsx'),
                XlsxLeser::lesen(self::FIXTURES . '/familien.xlsx'),
            );
            self::$zeilen = [];
            foreach ($ergebnis->zeilen as $zeile) {
                self::$zeilen[$zeile['mitgliedsnummer']] = $zeile;
            }
            self::$summary = $ergebnis->zusammenfassung();
            self::$csv = $ergebnis->csv();
        }

        return self::$zeilen;
    }

    /** @return iterable<string, array{string, string, string, string}> number, contribution type, active, old combination */
    public static function kombinationen(): iterable
    {
        yield 'Erwachsene + aktiv' => ['1001', 'Aktiv', '1', 'Erwachsene + aktiv'];
        yield 'Erwachsene' => ['1002', 'Passiv', '0', 'Erwachsene'];
        yield 'Kinder' => ['1003', 'Jugend/Schüler/Studenten/Azubis', '1', 'Kinder'];
        yield 'Jugendliche' => ['1004', 'Jugend/Schüler/Studenten/Azubis', '1', 'Jugendliche'];
        yield 'Rentner + aktiv' => ['1005', 'Rentner aktiv', '1', 'Rentner + aktiv'];
        yield 'Rentner' => ['1006', 'Rentner passiv', '0', 'Rentner'];
        yield 'Familienkarte, aktiv aus der Notiz' => ['1007', 'Familie', '1', 'Familienkarte'];
        yield 'Beitragsfrei FK, passiv aus der Notiz' => ['1008', '', '0', 'Beitragsfrei FK'];
        yield 'Beitragsfrei FK, aktiv aus der Notiz' => ['1009', '', '1', 'Beitragsfrei FK'];
        yield 'Mindestbeitrag + aktiv' => ['1010', 'Mindestbeitrag', '1', 'Mindestbeitrag + aktiv'];
        yield 'Mindestbeitrag' => ['1011', 'Mindestbeitrag', '0', 'Mindestbeitrag'];
        yield 'Beitragsfrei' => ['1012', 'Beitragsfrei', '1', 'Beitragsfrei'];
    }

    #[DataProvider('kombinationen')]
    public function testAllTwelveContributionCombinations(string $nummer, string $beitragsart, string $aktiv, string $kombination): void
    {
        $zeile = self::zeilen()[$nummer];

        self::assertSame($beitragsart, $zeile['beitragsart']);
        self::assertSame($aktiv, $zeile['aktiv']);
        self::assertMatchesRegularExpression('/^  ' . preg_quote($kombination, '/') . '\s+\d+$/m', (string) self::$summary);
    }

    public function testRowWithoutMembershipIsNotImported(): void
    {
        self::assertArrayNotHasKey('1013', self::zeilen());
        self::assertArrayNotHasKey('1023', self::zeilen());
        self::assertCount(25, self::zeilen());
    }

    public function testPayerWithoutMembershipBecomesAccountHolder(): void
    {
        $zeile = self::zeilen()['1014'];

        self::assertSame('Zora Zahlerin', $zeile['kontoinhaber']);
        self::assertSame('DE02120300000000202051', $zeile['iban']);
        self::assertSame('lastschrift', $zeile['zahlart']);
    }

    public function testPayerNameComesFromHisRowWhenThePayerColumnsAreEmpty(): void
    {
        self::assertSame('Zeno Zahler', self::zeilen()['1022']['kontoinhaber']);
        self::assertSame('DE89370400440532013000', self::zeilen()['1022']['iban']);
    }

    public function testPayingMemberIsNotMistakenForAnOtherPayer(): void
    {
        self::assertSame('', self::zeilen()['1001']['kontoinhaber']);
        self::assertSame('Erika Beispiel', self::zeilen()['1018']['kontoinhaber']);
    }

    public function testFamiliesAndRoles(): void
    {
        $zeilen = self::zeilen();

        self::assertSame(['501', 'Familie Familie', 'zahler'], [$zeilen['1007']['familie'], $zeilen['1007']['familie_name'], $zeilen['1007']['familie_rolle']]);
        self::assertSame('elternteil', $zeilen['1008']['familie_rolle']);
        self::assertSame('kind', $zeilen['1009']['familie_rolle']);
        self::assertSame(['502', 'zahler', 'elternteil', 'kind'], [
            $zeilen['1026']['familie'], $zeilen['1026']['familie_rolle'], $zeilen['1027']['familie_rolle'], $zeilen['1028']['familie_rolle'],
        ]);
        // Not in a family: no key, no role - and the hint shows up in the summary.
        self::assertSame(['', '', ''], [$zeilen['1019']['familie'], $zeilen['1019']['familie_name'], $zeilen['1019']['familie_rolle']]);
        self::assertSame(['', ''], [$zeilen['1001']['familie'], $zeilen['1001']['familie_rolle']]);
        self::assertStringContainsString('Familienkarte ohne Familie:', (string) self::$summary);
        self::assertStringContainsString('Beitragsfrei FK ohne Familie:', (string) self::$summary);
        self::assertStringContainsString('Familienzeilen ohne passendes Mitglied:', (string) self::$summary);
    }

    public function testAgeLimitIsMeasuredOnTheReferenceDay(): void
    {
        // Pia is born 23.12.2012: 12 on 01.10.2026, but 18 on 01.01.2031.
        $spaeter = (new Umwandler(new \DateTimeImmutable('2031-01-01')))->umwandeln(
            XlsxLeser::lesen(self::FIXTURES . '/mitglieder.xlsx'),
            XlsxLeser::lesen(self::FIXTURES . '/familien.xlsx'),
        );
        $rollen = array_column($spaeter->zeilen, 'familie_rolle', 'mitgliedsnummer');

        self::assertSame('kind', self::zeilen()['1009']['familie_rolle']);
        self::assertSame('elternteil', $rollen['1009']);
    }

    public function testTrainerGetsFunctionAndLicence(): void
    {
        $zeilen = self::zeilen();

        self::assertSame(['Übungsleiter', 'C-00815'], [$zeilen['1016']['funktionen'], $zeilen['1016']['lizenznummer']]);
        self::assertSame(['', ''], [$zeilen['1001']['funktionen'], $zeilen['1001']['lizenznummer']]);
        self::assertSame('selbst', $zeilen['1016']['zahlart']);
    }

    public function testPlaceholderAndBrokenDatesBecomeEmpty(): void
    {
        $zeilen = self::zeilen();

        self::assertSame('', $zeilen['1017']['eintrittsdatum']);
        self::assertSame('', $zeilen['1017']['geburtsdatum'], '31.02. does not exist');
        self::assertSame('01.04.2010', $zeilen['1001']['eintrittsdatum']);
        self::assertSame(['31.12.2025', 'Umzug'], [$zeilen['1021']['austrittsdatum'], $zeilen['1021']['austrittsgrund']]);
    }

    public function testDepartmentsAreBlsvNumbers(): void
    {
        $zeilen = self::zeilen();

        self::assertSame('09', $zeilen['1001']['abteilungen'], 'two slots of the same department count once');
        self::assertSame('01', $zeilen['1002']['abteilungen']);
        self::assertSame('16', $zeilen['1005']['abteilungen']);
        self::assertSame('34', $zeilen['1007']['abteilungen'], '"Turnen" is BLSV 34');
        self::assertSame('09,34', $zeilen['1015']['abteilungen']);
    }

    public function testHistoryColumnsAreIgnored(): void
    {
        // 1001 has "Kinder" in the history group - only the current group counts.
        self::assertSame('Aktiv', self::zeilen()['1001']['beitragsart']);
        self::assertSame('09', self::zeilen()['1001']['abteilungen']);
    }

    public function testIbanWithoutBlanksAndPlainFields(): void
    {
        $zeile = self::zeilen()['1001'];

        self::assertSame('DE89370400440532013000', $zeile['iban']);
        self::assertSame('COBADEFFXXX', $zeile['bic']);
        self::assertSame(['1001', 'Herr', 'Erika', 'Beispiel', 'w', '12.03.1980'], [
            $zeile['mitgliedsnummer'], $zeile['anrede'], $zeile['vorname'], $zeile['nachname'], $zeile['geschlecht'], $zeile['geburtsdatum'],
        ]);
        self::assertSame(['Musterweg 1', '80331', 'Beispielstadt', 'D', 'erika@example.org'], [
            $zeile['strasse'], $zeile['plz'], $zeile['ort'], $zeile['land'], $zeile['email'],
        ]);
        self::assertSame('01067', self::zeilen()['1022']['plz'], 'a number cell lost the leading zero');
    }

    public function testNotesAreMergedWithoutDuplicates(): void
    {
        $zeilen = self::zeilen();

        self::assertSame('Gymnastik aktiv', $zeilen['1007']['bemerkung'], 'identical notes only once');
        self::assertSame("Zeile eins\nZeile zwei; mit \"Zitat\"\nWirtschaftshelfer", $zeilen['1022']['bemerkung']);
    }

    public function testUnknownContributionNameIsReportedAndMemberKept(): void
    {
        $zeile = self::zeilen()['1024'];

        self::assertSame('', $zeile['beitragsart']);
        self::assertStringContainsString('unbekannter Beitragsname "Sondertarif": 1', (string) self::$summary);
    }

    public function testNotesWithInaktivAreNotActive(): void
    {
        self::assertSame('0', self::zeilen()['1020']['aktiv']);
    }

    public function testCsvFormat(): void
    {
        self::zeilen();
        $zeilen = str_getcsv(self::$csv, ';', '"', '');
        self::assertStringStartsWith("mitgliedsnummer;anrede;titel;vorname;nachname;", self::$csv);
        self::assertStringNotContainsString("\r", self::$csv);
        self::assertStringNotContainsString("\xEF\xBB\xBF", self::$csv, 'no BOM');
        self::assertNotSame([], $zeilen);

        // Round trip: the multi-line note survives as one field.
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, self::$csv);
        rewind($stream);
        $kopf = fgetcsv($stream, null, ';', '"', '');
        $gefunden = null;
        while (($feld = fgetcsv($stream, null, ';', '"', '')) !== false) {
            if ($feld[0] === '1022') {
                $gefunden = array_combine($kopf, $feld);
            }
        }
        fclose($stream);
        self::assertSame(Umwandler::SPALTEN, $kopf);
        self::assertSame("Zeile eins\nZeile zwei; mit \"Zitat\"\nWirtschaftshelfer", $gefunden['bemerkung'] ?? null);
    }

    public function testSummaryContainsNoNames(): void
    {
        self::zeilen();
        $text = (string) self::$summary;

        foreach (['Beispiel', 'Erika', 'Zahlerin', 'Musterweg', 'DE89', 'example.org', 'Fantasie', 'Ehrenwert'] as $verboten) {
            self::assertStringNotContainsString($verboten, $text);
        }
        self::assertMatchesRegularExpression('/als Mitglied übernommen:\s+25$/m', $text);
        self::assertMatchesRegularExpression('/keine Mitgliedschaft:\s+2$/m', $text);
        self::assertMatchesRegularExpression('/Elternteile.*:\s+2$/m', $text);
        self::assertMatchesRegularExpression('/Übungsleiter \(mit Lizenznummer\):\s+1$/m', $text);
    }

    public function testCommandLineWritesCsvToStdoutAndSummaryToStderr(): void
    {
        $aus = fopen('php://memory', 'w+');
        $fehler = fopen('php://memory', 'w+');

        $code = Kommandozeile::ausfuehren([self::FIXTURES . '/mitglieder.xlsx', self::FIXTURES . '/familien.xlsx', '--stichtag=01.10.2026'], $aus, $fehler);
        rewind($aus);
        rewind($fehler);

        self::assertSame(0, $code);
        self::assertSame(self::$csv, stream_get_contents($aus));
        self::assertStringContainsString('Umwandlung der Altdaten', (string) stream_get_contents($fehler));
    }

    public function testCommandLineRejectsWrongArgumentsAndWrongFiles(): void
    {
        $aus = fopen('php://memory', 'w+');
        $fehler = fopen('php://memory', 'w+');

        self::assertSame(2, Kommandozeile::ausfuehren(['nur-eine.xlsx'], $aus, $fehler));
        self::assertSame(2, Kommandozeile::ausfuehren(['a.xlsx', 'b.xlsx', '--stichtag=gestern'], $aus, $fehler));
        // Swapped files: the family file is no member file.
        self::assertSame(1, Kommandozeile::ausfuehren([self::FIXTURES . '/familien.xlsx', self::FIXTURES . '/mitglieder.xlsx'], $aus, $fehler));
        self::assertSame(1, Kommandozeile::ausfuehren([self::FIXTURES . '/gibt-es-nicht.xlsx', self::FIXTURES . '/familien.xlsx'], $aus, $fehler));
        rewind($aus);
        self::assertSame('', stream_get_contents($aus), 'nothing on STDOUT when it fails');
    }

    public function testFixtureFilesMatchTheirSource(): void
    {
        $daten = require self::FIXTURES . '/daten.php';
        $verzeichnis = sys_get_temp_dir() . '/altdaten-' . bin2hex(random_bytes(4));
        mkdir($verzeichnis);
        try {
            XlsxSchreiber::schreiben($verzeichnis . '/m.xlsx', $daten['mitglieder']);
            XlsxSchreiber::schreiben($verzeichnis . '/f.xlsx', $daten['familien']);

            self::assertSame(XlsxLeser::lesen($verzeichnis . '/m.xlsx'), XlsxLeser::lesen(self::FIXTURES . '/mitglieder.xlsx'), 'run tests/Fixtures/altdaten/erzeugen.php');
            self::assertSame(XlsxLeser::lesen($verzeichnis . '/f.xlsx'), XlsxLeser::lesen(self::FIXTURES . '/familien.xlsx'), 'run tests/Fixtures/altdaten/erzeugen.php');
        } finally {
            array_map('unlink', glob($verzeichnis . '/*') ?: []);
            rmdir($verzeichnis);
        }
    }

    public function testReaderReadsOnlyTheFirstSheetAndFillsGaps(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'xlsx');
        try {
            XlsxSchreiber::schreiben($datei, [['a', null, 'c'], [], [1, 2.5, 3.0]], [['zweites', 'Blatt']]);

            self::assertSame([['a', '', 'c'], ['1', '2.5', '3']], XlsxLeser::lesen($datei));
        } finally {
            unlink($datei);
        }
    }

    public function testReaderHandlesInlineStringsAndRichTextWithoutSharedStrings(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($datei, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="B1" t="inlineStr"><is><t>inline</t></is></c><c r="D1" t="str"><v>formel</v></c><c r="E1" t="b"><v>1</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();
        try {
            self::assertSame([['', 'inline', '', 'formel', '1']], XlsxLeser::lesen($datei));
        } finally {
            unlink($datei);
        }
    }

    public function testReaderRejectsFilesThatAreNoXlsx(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($datei, 'kein zip');
        try {
            $this->expectException(\RuntimeException::class);
            XlsxLeser::lesen($datei);
        } finally {
            unlink($datei);
        }
    }
}
