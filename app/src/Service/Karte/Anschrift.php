<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * A postal address as the map sees it (issue #91): street, postcode, town
 * and country - never a name. Two members with the same address in another
 * spelling ("Hauptstr. 1" and "hauptstr.  1") share one row in
 * geo_anschrift through normalisiert().
 */
final readonly class Anschrift
{
    /** Country of the member table when the field is empty (07: default "D"). */
    public const string LAND_STANDARD = 'D';

    public string $strasse;

    public string $plz;

    public string $ort;

    public string $land;

    public function __construct(string $strasse, string $plz, string $ort, string $land = self::LAND_STANDARD)
    {
        $this->strasse = self::bereinigen($strasse);
        $this->plz = self::bereinigen($plz);
        $this->ort = self::bereinigen($ort);
        $land = strtoupper(self::bereinigen($land));
        $this->land = $land === '' ? self::LAND_STANDARD : $land;
    }

    /** Without postcode and town there is nothing a geocoder could find. */
    public function istLeer(): bool
    {
        return $this->plz === '' && $this->ort === '';
    }

    /**
     * The form the hash is built from: lower case, single spaces, "str." and
     * "strasse" written as "straße" - enough for the spellings one club's
     * data has, without pretending to be an address parser.
     */
    public function normalisiert(): string
    {
        $strasse = mb_strtolower($this->strasse);
        $strasse = (string) preg_replace('/str(\.|asse\b)/u', 'straße', $strasse);
        $strasse = (string) preg_replace('/\s*-\s*/u', '-', $strasse);

        return implode('|', [$strasse, mb_strtolower($this->plz), mb_strtolower($this->ort), $this->land]);
    }

    /**
     * The town as the geocoder gets it: up to the first minus. The old
     * software wrote the district after the town ("Johannesberg - OAB"),
     * which the geocoder does not know; town and postcode are enough to
     * find the street. Without a minus, or with nothing before it, the
     * town stays as it is.
     */
    public function ortFuerSuche(): string
    {
        $vorne = trim(explode('-', $this->ort, 2)[0]);

        return $vorne !== '' ? $vorne : $this->ort;
    }

    public function hash(): string
    {
        return hash('sha256', $this->normalisiert());
    }

    /** One line for the page: "Hauptstraße 1, 12345 Musterstadt" (country only abroad). */
    public function text(): string
    {
        $ort = trim($this->plz . ' ' . $this->ort);
        $teile = array_values(array_filter([$this->strasse, $ort], static fn(string $t): bool => $t !== ''));
        if ($this->land !== self::LAND_STANDARD) {
            $teile[] = $this->land;
        }

        return implode(', ', $teile);
    }

    private static function bereinigen(string $wert): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $wert));
    }
}
