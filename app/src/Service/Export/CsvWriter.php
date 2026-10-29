<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Listen\ListenZeile;
use App\Listen\Spalte;

/**
 * CSV the way a German Excel opens it directly (docs/spec/09-listen-und-exporte.md
 * section 1): UTF-8 with BOM, `;` as separator, CRLF line ends, dates as
 * TT.MM.JJJJ, amounts with a decimal comma.
 *
 * Protection against formula injection (Excel and LibreOffice run a cell
 * starting with = + - @ as a formula, e.g. "=HYPERLINK(...)" from an
 * imported note): such a cell gets a leading apostrophe. Values that only
 * look like a number or phone number ("+49 170 ...", "-12,50") stay as
 * they are, so the file still moves to other software unchanged.
 */
final class CsvWriter
{
    public const string BOM = "\xEF\xBB\xBF";

    public const string TRENNER = ';';

    /**
     * The file in pieces: the header, then one piece per row.
     *
     * @param list<Spalte> $spalten
     * @param iterable<ListenZeile> $zeilen
     * @return \Generator<int, string>
     */
    public static function stuecke(array $spalten, iterable $zeilen): \Generator
    {
        yield self::BOM . self::zeile(array_map(static fn(Spalte $s): string => $s->titel, $spalten));
        foreach ($zeilen as $zeile) {
            yield self::zeile(array_map(
                static fn(Spalte $s): string => Zellwert::text($s, $zeile->werte[$s->schluessel] ?? null),
                $spalten,
            ));
        }
    }

    /** @param list<string> $felder */
    public static function zeile(array $felder): string
    {
        return implode(self::TRENNER, array_map(self::feld(...), $felder)) . "\r\n";
    }

    private static function feld(string $text): string
    {
        $text = self::ohneFormel($text);
        if (strpbrk($text, ";\"\r\n") === false) {
            return $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }

    public static function ohneFormel(string $text): string
    {
        if ($text === '' || strpbrk($text[0], "=+-@\t\r") === false) {
            return $text;
        }
        if (($text[0] === '+' || $text[0] === '-') && preg_match('/^[+-][0-9\s().,\/-]*$/D', $text) === 1) {
            return $text;
        }

        return "'" . $text;
    }
}
