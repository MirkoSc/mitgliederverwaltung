<?php

declare(strict_types=1);

namespace App\Support;

/**
 * BIC (SWIFT code) shape and comparison (docs/spec/07-mitglieder.md
 * section 1). A wrong BIC is still saved - it only gives a hint.
 */
final class Bic
{
    /** Upper case, without spaces. */
    public static function normalisieren(string $bic): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $bic));
    }

    /**
     * 8 or 11 characters: bank (4 letters), country (2 letters), location
     * (2 letters or digits), optionally the branch (3 letters or digits).
     * '' is not valid.
     */
    public static function istGueltig(string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', self::normalisieren($bic)) === 1;
    }

    /** Whether two BICs name the same office; "COBADEFF" equals "COBADEFFXXX". */
    public static function gleich(string $a, string $b): bool
    {
        $lang = static function (string $bic): string {
            $bic = self::normalisieren($bic);

            return strlen($bic) === 8 ? $bic . 'XXX' : $bic;
        };

        return $lang($a) === $lang($b);
    }
}
