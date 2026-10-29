<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Listen\ListenZeile;
use App\Listen\Spalte;
use App\Listen\SpaltenTyp;

/**
 * A small xlsx writer (docs/spec/09-listen-und-exporte.md section 1): one
 * sheet, the first row with the column titles in bold and frozen, dates as
 * real Excel dates, amounts and numbers as numbers. Text goes in as inline
 * strings, so there is no shared-strings part and no cell can be read as a
 * formula. Only the few XML parts Excel and LibreOffice insist on.
 */
final class XlsxWriter
{
    private const string NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** Style ids of styles.xml (cellXfs). */
    private const int STIL_KOPF = 1;
    private const int STIL_DATUM = 2;
    private const int STIL_GELD = 3;

    /**
     * @param list<Spalte> $spalten
     * @param iterable<ListenZeile> $zeilen
     */
    public static function erzeugen(array $spalten, iterable $zeilen, string $blatt): string
    {
        $zip = new ZipWriter();
        $zip->datei('[Content_Types].xml', self::xml(
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        ));
        $zip->datei('_rels/.rels', self::xml(
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        ));
        $zip->datei('xl/workbook.xml', self::xml(
            '<workbook xmlns="' . self::NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::attribut(self::blattname($blatt)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',
        ));
        $zip->datei('xl/_rels/workbook.xml.rels', self::xml(
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        ));
        $zip->datei('xl/styles.xml', self::xml(
            '<styleSheet xmlns="' . self::NS . '">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD.MM.YYYY"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',
        ));
        $zip->datei('xl/worksheets/sheet1.xml', self::tabelle($spalten, $zeilen));

        return $zip->inhalt();
    }

    /**
     * @param list<Spalte> $spalten
     * @param iterable<ListenZeile> $zeilen
     */
    private static function tabelle(array $spalten, iterable $zeilen): string
    {
        $breiten = array_map(static fn(Spalte $s): int => mb_strlen($s->titel) + 2, $spalten);
        $reihen = [];

        $kopf = '';
        foreach ($spalten as $i => $spalte) {
            $kopf .= self::textzelle(self::bezug($i, 1), $spalte->titel, self::STIL_KOPF);
        }
        $reihen[] = '<row r="1">' . $kopf . '</row>';

        $nummer = 1;
        foreach ($zeilen as $zeile) {
            $nummer++;
            $zellen = '';
            foreach ($spalten as $i => $spalte) {
                $wert = $zeile->werte[$spalte->schluessel] ?? null;
                $zellen .= self::zelle(self::bezug($i, $nummer), $spalte, $wert);
                $breiten[$i] = max($breiten[$i], min(60, mb_strlen(Zellwert::text($spalte, $wert)) + 2));
            }
            $reihen[] = '<row r="' . $nummer . '">' . $zellen . '</row>';
        }

        $spaltenbreiten = '';
        foreach ($breiten as $i => $breite) {
            $spaltenbreiten .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(8, $breite)) . '" customWidth="1"/>';
        }

        return self::xml(
            '<worksheet xmlns="' . self::NS . '">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $spaltenbreiten . '</cols>'
            . '<sheetData>' . implode('', $reihen) . '</sheetData>'
            . '</worksheet>',
        );
    }

    private static function zelle(string $bezug, Spalte $spalte, string|int|\DateTimeInterface|null $wert): string
    {
        if ($wert === null || $wert === '') {
            return '';
        }

        if ($spalte->typ === SpaltenTyp::Datum) {
            $tag = Zellwert::tag($spalte, $wert);
            if ($tag !== null) {
                return '<c r="' . $bezug . '" s="' . self::STIL_DATUM . '"><v>' . self::seriennummer($tag) . '</v></c>';
            }
        } elseif ($spalte->typ === SpaltenTyp::Zahl && is_int($wert)) {
            return '<c r="' . $bezug . '"><v>' . $wert . '</v></c>';
        } elseif ($spalte->typ === SpaltenTyp::Geld && is_int($wert)) {
            return '<c r="' . $bezug . '" s="' . self::STIL_GELD . '"><v>' . self::betrag($wert) . '</v></c>';
        }

        // Text - also whatever does not match its column's type, as typed.
        return self::textzelle($bezug, Zellwert::text($spalte, $wert), 0);
    }

    private static function textzelle(string $bezug, string $text, int $stil): string
    {
        return '<c r="' . $bezug . '" t="inlineStr"' . ($stil > 0 ? ' s="' . $stil . '"' : '') . '><is><t xml:space="preserve">'
            . self::text($text) . '</t></is></c>';
    }

    /** Days since 1899-12-30, which is how Excel counts (including its 1900 leap-year error). */
    private static function seriennummer(\DateTimeImmutable $tag): int
    {
        $null = new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
        $tag = new \DateTimeImmutable($tag->format('Y-m-d'), new \DateTimeZone('UTC'));

        return (int) $null->diff($tag)->format('%r%a');
    }

    /** Cent as "-12.50" - by integer arithmetic, no float on the way. */
    private static function betrag(int $cent): string
    {
        $absolut = abs($cent);

        return ($cent < 0 ? '-' : '') . intdiv($absolut, 100) . '.' . str_pad((string) ($absolut % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "A1", "B7", ..., "AA3" for column index 0, row 1. */
    private static function bezug(int $spalte, int $zeile): string
    {
        $buchstaben = '';
        for ($n = $spalte + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $buchstaben = chr(65 + ($n - 1) % 26) . $buchstaben;
        }

        return $buchstaben . $zeile;
    }

    /** Sheet names: at most 31 characters, none of []:*?/\ and not empty. */
    private static function blattname(string $name): string
    {
        $name = trim(mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name), 0, 31));

        return $name === '' ? 'Liste' : $name;
    }

    private static function xml(string $teil): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $teil;
    }

    /** XML text: control characters XML 1.0 forbids are dropped. */
    private static function text(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function attribut(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
