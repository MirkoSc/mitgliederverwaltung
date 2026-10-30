<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * Where the small map opens when a point is to be set by hand for an
 * address the map has no dot for (issue #108) - from the data there is,
 * without asking the geocoder: the middle of the dots with the same
 * postcode, else of the same town, else of all dots (where the club's
 * members live), else the middle of Germany.
 */
final readonly class Kartenmitte
{
    public const float DEUTSCHLAND_LAT = 51.1634;

    public const float DEUTSCHLAND_LON = 10.4477;

    public function __construct(public float $lat, public float $lon, public int $zoom)
    {
    }

    /**
     * @param array<string, Anschrift> $anschriften every current address, by hash
     * @param array<string, array{status: ?GeoStatus, lat: ?float, lon: ?float}> $koordinaten by hash
     */
    public static function fuer(Anschrift $anschrift, array $anschriften, array $koordinaten): self
    {
        $plz = [];
        $ort = [];
        $alle = [];
        $gesuchterOrt = mb_strtolower($anschrift->ortFuerSuche());
        foreach ($anschriften as $hash => $andere) {
            $geo = $koordinaten[$hash] ?? null;
            if ($geo === null || $geo['status']?->hatPunkt() !== true || $geo['lat'] === null || $geo['lon'] === null) {
                continue;
            }
            $punkt = [$geo['lat'], $geo['lon']];
            $alle[] = $punkt;
            if ($anschrift->plz !== '' && $andere->plz === $anschrift->plz) {
                $plz[] = $punkt;
            }
            if ($gesuchterOrt !== '' && mb_strtolower($andere->ortFuerSuche()) === $gesuchterOrt) {
                $ort[] = $punkt;
            }
        }

        return match (true) {
            $plz !== [] => self::mitte($plz, 14),
            $ort !== [] => self::mitte($ort, 13),
            $alle !== [] => self::mitte($alle, 11),
            default => new self(self::DEUTSCHLAND_LAT, self::DEUTSCHLAND_LON, 6),
        };
    }

    /** @param non-empty-list<array{float, float}> $punkte */
    private static function mitte(array $punkte, int $zoom): self
    {
        $lat = array_sum(array_column($punkte, 0)) / count($punkte);
        $lon = array_sum(array_column($punkte, 1)) / count($punkte);

        return new self($lat, $lon, $zoom);
    }
}
