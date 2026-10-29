<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Listen\ListenZeile;
use App\Listen\Spalte;
use App\Service\Export\XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * The xlsx is read back the way a spreadsheet program does: as a ZIP whose
 * parts are well-formed XML, with the cells in them.
 */
final class XlsxWriterTest extends TestCase
{
    /** @var list<string> */
    private array $dateien = [];

    protected function tearDown(): void
    {
        foreach ($this->dateien as $datei) {
            @unlink($datei);
        }
    }

    /** @param iterable<ListenZeile> $zeilen */
    private function oeffnen(array $spalten, iterable $zeilen, string $blatt = 'Liste'): \ZipArchive
    {
        $datei = tempnam(sys_get_temp_dir(), 'mv-xlsx-');
        self::assertIsString($datei);
        $this->dateien[] = $datei;
        file_put_contents($datei, XlsxWriter::erzeugen($spalten, $zeilen, $blatt));

        $zip = new \ZipArchive();
        // CHECKCONS: also verifies sizes and checksums of every part.
        self::assertTrue($zip->open($datei, \ZipArchive::CHECKCONS), 'the xlsx is not a valid ZIP');

        return $zip;
    }

    private static function teil(\ZipArchive $zip, string $name): \SimpleXMLElement
    {
        $inhalt = $zip->getFromName($name);
        self::assertIsString($inhalt, $name . ' is missing');
        $xml = simplexml_load_string($inhalt);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $name . ' is not well-formed XML');
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xml;
    }

    public function testItIsAValidZipWithTheParts(): void
    {
        $zip = $this->oeffnen([Spalte::text('a', 'A')], []);

        $namen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $namen[] = $zip->getNameIndex($i);
            self::teil($zip, (string) $zip->getNameIndex($i)); // every part is well-formed
        }
        sort($namen);
        self::assertSame(
            ['[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'],
            $namen,
        );
    }

    public function testOneSheetWithTheGivenName(): void
    {
        $zip = $this->oeffnen([Spalte::text('a', 'A')], [], 'Geburtstage');

        $blaetter = self::teil($zip, 'xl/workbook.xml')->xpath('//m:sheet');
        self::assertIsArray($blaetter);
        self::assertCount(1, $blaetter);
        self::assertSame('Geburtstage', (string) $blaetter[0]['name']);
    }

    public function testASheetNameExcelWouldRejectIsCleaned(): void
    {
        $zip = $this->oeffnen([Spalte::text('a', 'A')], [], 'Mitglieder: [alle] / 2027 mit einem sehr langen Namen');

        $blaetter = self::teil($zip, 'xl/workbook.xml')->xpath('//m:sheet');
        self::assertIsArray($blaetter);
        $name = (string) $blaetter[0]['name'];
        self::assertLessThanOrEqual(31, mb_strlen($name));
        self::assertSame(0, preg_match('/[\[\]:*?\/\\\\]/', $name));
    }

    public function testFirstRowHoldsTheTitlesInBold(): void
    {
        $zip = $this->oeffnen(
            [Spalte::text('name', 'Name'), Spalte::text('ort', 'Ort')],
            [new ListenZeile(['name' => 'Anna', 'ort' => 'Ulm'])],
        );

        $zellen = self::teil($zip, 'xl/worksheets/sheet1.xml')->xpath('//m:row[@r="1"]/m:c');
        self::assertIsArray($zellen);
        self::assertSame(['Name', 'Ort'], array_map(static fn(\SimpleXMLElement $c): string => (string) $c->is->t, $zellen));
        foreach ($zellen as $zelle) {
            self::assertSame('1', (string) $zelle['s'], 'the title cell uses the bold style');
        }

        // style 1 is the bold one
        $stile = self::teil($zip, 'xl/styles.xml');
        $xfs = $stile->xpath('//m:cellXfs/m:xf');
        self::assertIsArray($xfs);
        $schrift = (int) $xfs[1]['fontId'];
        $fonts = $stile->xpath('//m:fonts/m:font');
        self::assertIsArray($fonts);
        self::assertTrue(isset($fonts[$schrift]->b), 'font of style 1 is bold');
    }

    public function testADateIsARealExcelDateCell(): void
    {
        $zip = $this->oeffnen(
            [Spalte::datum('d', 'Datum')],
            [
                new ListenZeile(['d' => '2000-02-29']),
                new ListenZeile(['d' => new \DateTimeImmutable('2027-03-01 18:30')]),
                new ListenZeile(['d' => '1900-03-01']),
            ],
        );

        $blatt = self::teil($zip, 'xl/worksheets/sheet1.xml');
        $zellen = $blatt->xpath('//m:row[position()>1]/m:c');
        self::assertIsArray($zellen);

        // Excel counts days from 1899-12-30: 36585 = 29.02.2000, 46447 = 01.03.2027, 61 = 01.03.1900
        self::assertSame(['36585', '46447', '61'], array_map(static fn(\SimpleXMLElement $c): string => (string) $c->v, $zellen));
        foreach ($zellen as $zelle) {
            self::assertSame('', (string) $zelle['t'], 'a number cell, not a string');
            self::assertSame('2', (string) $zelle['s']);
        }

        // style 2 shows it as a date, not as the number
        $xfs = self::teil($zip, 'xl/styles.xml')->xpath('//m:cellXfs/m:xf');
        self::assertIsArray($xfs);
        $format = self::teil($zip, 'xl/styles.xml')->xpath('//m:numFmt[@numFmtId="' . $xfs[2]['numFmtId'] . '"]');
        self::assertIsArray($format);
        self::assertSame('DD.MM.YYYY', (string) $format[0]['formatCode']);
    }

    public function testNumbersAndAmountsAreNumberCells(): void
    {
        $zip = $this->oeffnen(
            [Spalte::zahl('z', 'Zahl'), Spalte::geld('g', 'Betrag'), Spalte::geld('h', 'Minus'), Spalte::geld('n', 'Null')],
            [new ListenZeile(['z' => 42, 'g' => 1250, 'h' => -5, 'n' => 0])],
        );

        $zellen = self::teil($zip, 'xl/worksheets/sheet1.xml')->xpath('//m:row[@r="2"]/m:c');
        self::assertIsArray($zellen);
        self::assertSame(['42', '12.50', '-0.05', '0.00'], array_map(static fn(\SimpleXMLElement $c): string => (string) $c->v, $zellen));
        self::assertSame('', (string) $zellen[0]['t']);
    }

    public function testEmptyCellsAreLeftOutAndReferencesStayRight(): void
    {
        $zip = $this->oeffnen(
            [Spalte::text('a', 'A'), Spalte::text('b', 'B'), Spalte::text('c', 'C')],
            [new ListenZeile(['a' => 'eins', 'c' => 'drei'])],
        );

        $zellen = self::teil($zip, 'xl/worksheets/sheet1.xml')->xpath('//m:row[@r="2"]/m:c');
        self::assertIsArray($zellen);
        self::assertSame(['A2', 'C2'], array_map(static fn(\SimpleXMLElement $c): string => (string) $c['r'], $zellen));
    }

    public function testColumnReferencesContinueAfterZ(): void
    {
        $spalten = [];
        for ($i = 0; $i < 28; $i++) {
            $spalten[] = Spalte::text('s' . $i, 'S' . $i);
        }
        $zip = $this->oeffnen($spalten, []);

        $zellen = self::teil($zip, 'xl/worksheets/sheet1.xml')->xpath('//m:row[@r="1"]/m:c');
        self::assertIsArray($zellen);
        self::assertSame(['Z1', 'AA1', 'AB1'], array_map(static fn(\SimpleXMLElement $c): string => (string) $c['r'], array_slice($zellen, 25, 3)));
    }

    public function testSpecialCharactersSurviveAndControlCharactersAreDropped(): void
    {
        $zip = $this->oeffnen(
            [Spalte::text('t', 'Text & <Titel>')],
            [new ListenZeile(['t' => "Müller & Söhne <GmbH> \"Q\"\nzweite Zeile\x01\x0B ende"])],
        );

        $blatt = self::teil($zip, 'xl/worksheets/sheet1.xml');
        $kopf = $blatt->xpath('//m:row[@r="1"]/m:c/m:is/m:t');
        $wert = $blatt->xpath('//m:row[@r="2"]/m:c/m:is/m:t');
        self::assertIsArray($kopf);
        self::assertIsArray($wert);
        self::assertSame('Text & <Titel>', (string) $kopf[0]);
        self::assertSame("Müller & Söhne <GmbH> \"Q\"\nzweite Zeile ende", (string) $wert[0]);
    }

    public function testATextThatLooksLikeAFormulaStaysText(): void
    {
        $zip = $this->oeffnen([Spalte::text('t', 'T')], [new ListenZeile(['t' => '=1+1'])]);

        $zelle = self::teil($zip, 'xl/worksheets/sheet1.xml')->xpath('//m:row[@r="2"]/m:c');
        self::assertIsArray($zelle);
        self::assertSame('inlineStr', (string) $zelle[0]['t']);
        self::assertFalse(isset($zelle[0]->f), 'no formula element');
        self::assertSame('=1+1', (string) $zelle[0]->is->t);
    }

    public function testHeaderRowIsFrozen(): void
    {
        $zip = $this->oeffnen([Spalte::text('a', 'A')], []);

        $pane = self::teil($zip, 'xl/worksheets/sheet1.xml')->xpath('//m:pane');
        self::assertIsArray($pane);
        self::assertSame('frozen', (string) $pane[0]['state']);
    }

    public function testTheSameInputGivesTheSameBytes(): void
    {
        $spalten = [Spalte::text('a', 'A')];
        $zeilen = [new ListenZeile(['a' => 'x'])];

        self::assertSame(XlsxWriter::erzeugen($spalten, $zeilen, 'L'), XlsxWriter::erzeugen($spalten, $zeilen, 'L'));
    }
}
