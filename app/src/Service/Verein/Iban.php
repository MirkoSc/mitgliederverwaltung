<?php

declare(strict_types=1);

namespace App\Service\Verein;

/**
 * IBAN handling for the club data (docs/spec/11-vereinsdaten.md section 1):
 * normalised on save, checked by its check digits - a hint, never a block.
 */
final class Iban
{
    /** Upper case, no spaces or other separators. */
    public static function normalisieren(string $iban): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    /** Format and ISO 7064 mod 97-10 check digits of an already normalised IBAN. */
    public static function istGueltig(string $iban): bool
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban) !== 1) {
            return false;
        }

        $umgestellt = substr($iban, 4) . substr($iban, 0, 4);
        $rest = 0;
        foreach (str_split($umgestellt) as $zeichen) {
            // Letters count as 10..35, i.e. two digits.
            $wert = ctype_digit($zeichen) ? (string) $zeichen : (string) (ord($zeichen) - 55);
            foreach (str_split($wert) as $ziffer) {
                $rest = ($rest * 10 + (int) $ziffer) % 97;
            }
        }

        return $rest === 1;
    }
}
