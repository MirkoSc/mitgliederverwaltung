<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Countries as the application stores and shows them (07 section 1): stored
 * is the ISO 3166-1 alpha-2 code ("DE"), shown the German name
 * ("Deutschland"). Input is understood as name, ISO code or one of the old
 * licence-plate codes of the former software ("D", "A", "CH", "F" ...).
 * The list is app/data/laender.php (tools/laender-erzeugen.mjs).
 */
final class Laender
{
    public const string STANDARD = 'DE';

    /**
     * Licence-plate codes of the former software that are no ISO alpha-2
     * code - exactly the ones migrations/300_land_iso.sql rewrote in the
     * member table. Each ISO code appears once, so kennzeichenImHash() can
     * map back.
     */
    private const array KFZ = [
        'D' => 'DE', 'A' => 'AT', 'F' => 'FR', 'B' => 'BE', 'L' => 'LU', 'I' => 'IT', 'E' => 'ES',
        'UK' => 'GB', 'H' => 'HU', 'FL' => 'LI', 'S' => 'SE', 'N' => 'NO', 'P' => 'PT', 'SLO' => 'SI',
        'USA' => 'US', 'IRL' => 'IE', 'FIN' => 'FI', 'RUS' => 'RU',
    ];

    /**
     * Further spellings that are understood but were never rewritten in
     * the database (alpha-3 codes); a stored one stays until the member is
     * saved again.
     */
    private const array WEITERE = [
        'GBR' => 'GB', 'DEU' => 'DE', 'AUT' => 'AT', 'CHE' => 'CH', 'FRA' => 'FR', 'NLD' => 'NL',
        'BEL' => 'BE', 'ITA' => 'IT', 'ESP' => 'ES',
    ];

    /** @var array<string, string>|null code => German name, in the order of the selection */
    private static ?array $liste = null;

    /** @var array<string, string>|null ISO code => licence-plate code of KFZ */
    private static ?array $zurueck = null;

    /** @var array<string, string>|null lower-case name => code */
    private static ?array $nachName = null;

    /**
     * The ISO code for what was typed (name, ISO code or licence-plate code,
     * case does not count); null when nothing fits. Empty input is no country.
     */
    public static function code(string $eingabe): ?string
    {
        $eingabe = trim((string) preg_replace('/\s+/u', ' ', $eingabe));
        if ($eingabe === '') {
            return null;
        }
        $gross = mb_strtoupper($eingabe);
        if (isset(self::liste()[$gross])) {
            return $gross;
        }
        if (isset(self::KFZ[$gross]) || isset(self::WEITERE[$gross])) {
            return self::KFZ[$gross] ?? self::WEITERE[$gross];
        }
        if (self::$nachName === null) {
            self::$nachName = [];
            foreach (self::liste() as $code => $name) {
                self::$nachName[mb_strtolower($name)] = $code;
            }
        }

        return self::$nachName[mb_strtolower($eingabe)] ?? null;
    }

    /**
     * The German name for a stored value or an old code; what is unknown
     * comes back as it is (an old version may hold anything), '' stays ''.
     */
    public static function name(string $wert): string
    {
        $code = self::code($wert);

        return $code === null ? $wert : self::liste()[$code];
    }

    /** The stored form of an old or typed value; unknown values stay as they are. */
    public static function iso(string $wert): string
    {
        return self::code($wert) ?? $wert;
    }

    /**
     * The country as the address hash of the map holds it
     * (App\Service\Karte\Anschrift): the value as the first release stored
     * it. An ISO code that migration 300 made from a licence-plate code
     * goes back to that code ("AT" -> "A", "DE" -> "D"); everything else
     * stays as stored, upper case ("CH", "DEU", "CDN"). So the coordinates
     * found before the switch to ISO codes stay valid. '' stays ''.
     */
    public static function kennzeichenImHash(string $wert): string
    {
        $gross = mb_strtoupper(trim($wert));
        if (isset(self::KFZ[$gross])) {
            return $gross;
        }
        self::$zurueck ??= array_flip(self::KFZ);

        return self::$zurueck[$gross] ?? $gross;
    }

    /**
     * All countries for the selection: Germany, the neighbours, then the rest
     * alphabetically (the order of the data file).
     *
     * @return array<string, string> code => German name
     */
    public static function auswahl(): array
    {
        return self::liste();
    }

    /** @return array<string, string> */
    private static function liste(): array
    {
        /** @var array<string, string> $liste */
        $liste = self::$liste ??= require __DIR__ . '/../../data/laender.php';

        return $liste;
    }
}
