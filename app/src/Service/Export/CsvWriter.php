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
 * Values are written as they are. A phone number like "+49 170 ..." would
 * not survive the usual protection against formula injection (a leading
 * apostrophe), and the file is also meant for moving to other software.
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
        if (strpbrk($text, ";\"\r\n") === false) {
            return $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }
}
