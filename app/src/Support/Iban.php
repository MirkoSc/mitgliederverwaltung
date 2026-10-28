<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IBAN normalisation and check digits (docs/spec/07-mitglieder.md
 * section 1). An invalid IBAN is still saved - it only gives a hint, never
 * a block (CLAUDE.md, "Hinweise statt Sperren").
 */
final class Iban
{
    /**
     * Lengths of the SEPA countries (ISO 13616 registry). A country that is
     * not listed is checked by format and check digits alone.
     */
    private const array LAENGEN = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18,
        'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27, 'HR' => 21, 'HU' => 28,
        'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28,
        'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
        'VA' => 22,
    ];

    /** Upper case, without spaces (and the dashes some people type). */
    public static function normalisieren(string $iban): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', $iban));
    }

    /** Format, country length and the mod-97 check digits; '' is invalid. */
    public static function istGueltig(string $iban): bool
    {
        $iban = self::normalisieren($iban);
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban) !== 1) {
            return false;
        }
        $land = substr($iban, 0, 2);
        if (isset(self::LAENGEN[$land]) && strlen($iban) !== self::LAENGEN[$land]) {
            return false;
        }

        // Move country and check digits to the end, letters to numbers
        // (A = 10 ... Z = 35); the remainder mod 97 must be 1. Computed in
        // chunks so no number exceeds an integer.
        $umgestellt = substr($iban, 4) . substr($iban, 0, 4);
        $ziffern = '';
        foreach (str_split($umgestellt) as $zeichen) {
            $ziffern .= ctype_alpha($zeichen) ? (string) (ord($zeichen) - 55) : $zeichen;
        }
        $rest = 0;
        foreach (str_split($ziffern, 7) as $teil) {
            $rest = (int) ($rest . $teil) % 97;
        }

        return $rest === 1;
    }

    /** "DE02 1203 0000 0000 2020 51" - for display only. */
    public static function anzeigen(string $iban): string
    {
        return trim(chunk_split(self::normalisieren($iban), 4, ' '));
    }
}
