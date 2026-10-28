<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is integer cent, never float (CLAUDE.md section 5). This is the one
 * place that turns cent into text and text into cent - by string
 * arithmetic, so no amount ever passes through a float on the way.
 */
final class Geld
{
    /** Non-breaking space: "12,50 €" must not wrap between number and sign. */
    private const string LEERZEICHEN = "\u{00A0}";

    /** More digits than this are no club amount but a typo. */
    private const int MAX_ZIFFERN = 15;

    /** "1.234,56 €", "-0,05 €". */
    public static function anzeigen(int $cent): string
    {
        return self::betrag($cent) . self::LEERZEICHEN . '€';
    }

    /**
     * "1234,56" - for form fields and exports (no thousands separator, no
     * currency sign, so it parses back unchanged).
     */
    public static function eingabeWert(int $cent): string
    {
        [$vorzeichen, $euro, $rest] = self::zerlegen($cent);

        return $vorzeichen . $euro . ',' . $rest;
    }

    /**
     * Cent from what a person types: "12", "12,5", "12,50", "1.234,56",
     * "1234.56", "-3,00 €". Null for anything else - the caller tells the
     * user what to fix instead of guessing.
     */
    public static function ausEingabe(string $eingabe): ?int
    {
        $text = str_replace([' ', self::LEERZEICHEN, '€'], '', trim($eingabe));
        if (preg_match('/^([+-]?)(.+)$/D', $text, $teile) !== 1) {
            return null;
        }
        [, $vorzeichen, $zahl] = $teile;

        if (preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+),(\d{1,2})$/D', $zahl, $m) === 1) {
            // Comma as decimal mark, dots (if any) group thousands.
            [$euro, $nachkomma] = [str_replace('.', '', $m[1]), $m[2]];
        } elseif (preg_match('/^(\d+)\.(\d{1,2})$/D', $zahl, $m) === 1) {
            // A single dot with one or two digits after it is a decimal point.
            [$euro, $nachkomma] = [$m[1], $m[2]];
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $zahl) === 1 || preg_match('/^\d+$/D', $zahl) === 1) {
            // "1.234" groups thousands; whole euros.
            [$euro, $nachkomma] = [str_replace('.', '', $zahl), '0'];
        } else {
            return null;
        }

        $euro = ltrim($euro, '0');
        if (strlen($euro) > self::MAX_ZIFFERN) {
            return null;
        }
        $cent = (int) (($euro === '' ? '0' : $euro) . str_pad($nachkomma, 2, '0'));

        return $vorzeichen === '-' ? -$cent : $cent;
    }

    private static function betrag(int $cent): string
    {
        [$vorzeichen, $euro, $rest] = self::zerlegen($cent);
        $gruppiert = strrev(implode('.', str_split(strrev($euro), 3)));

        return $vorzeichen . $gruppiert . ',' . $rest;
    }

    /**
     * @return array{string, string, string} sign, euro digits, two cent digits
     */
    private static function zerlegen(int $cent): array
    {
        $ziffern = str_pad(ltrim((string) $cent, '-'), 3, '0', STR_PAD_LEFT);

        return [
            $cent < 0 ? '-' : '',
            substr($ziffern, 0, -2),
            substr($ziffern, -2),
        ];
    }
}
