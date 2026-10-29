<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Writes a minimal xlsx (one or more sheets, text and numbers) for tests and
 * for the invented legacy fixtures. Text goes into sharedStrings, int/float
 * values become number cells.
 */
final class XlsxSchreiber
{
    /**
     * @param list<list<string|int|float|null>> $zeilen first sheet
     * @param list<list<string|int|float|null>>|null $zweitesBlatt optional second sheet (must never be read)
     */
    public static function schreiben(string $pfad, array $zeilen, ?array $zweitesBlatt = null): void
    {
        $texte = [];
        $blaetter = [self::blatt($zeilen, $texte)];
        if ($zweitesBlatt !== null) {
            $blaetter[] = self::blatt($zweitesBlatt, $texte);
        }

        $zip = new \ZipArchive();
        if ($zip->open($pfad, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot write ' . $pfad);
        }
        $typen = '';
        $arbeitsmappe = '';
        $beziehungen = '';
        foreach ($blaetter as $i => $xml) {
            $nr = $i + 1;
            $zip->addFromString("xl/worksheets/sheet$nr.xml", $xml);
            $typen .= "<Override PartName=\"/xl/worksheets/sheet$nr.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $arbeitsmappe .= "<sheet name=\"Tabelle$nr\" sheetId=\"$nr\" r:id=\"rId$nr\"/>";
            $beziehungen .= "<Relationship Id=\"rId$nr\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$nr.xml\"/>";
        }
        $strings = '';
        foreach ($texte as $text) {
            $strings .= '<si><t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</t></si>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' . $typen . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $arbeitsmappe . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $beziehungen . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($texte) . '" uniqueCount="' . count($texte) . '">' . $strings . '</sst>');
        $zip->close();
    }

    /**
     * @param list<list<string|int|float|null>> $zeilen
     * @param list<string> $texte shared strings, extended
     */
    private static function blatt(array $zeilen, array &$texte): string
    {
        $xml = '';
        foreach ($zeilen as $r => $zeile) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach ($zeile as $c => $wert) {
                if ($wert === null || $wert === '') {
                    continue;
                }
                $ref = self::spalte($c) . ($r + 1);
                if (is_string($wert)) {
                    $index = array_search($wert, $texte, true);
                    if ($index === false) {
                        $texte[] = $wert;
                        $index = count($texte) - 1;
                    }
                    $xml .= "<c r=\"$ref\" t=\"s\"><v>$index</v></c>";
                } else {
                    $xml .= "<c r=\"$ref\"><v>$wert</v></c>";
                }
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>';
    }

    private static function spalte(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }
}
