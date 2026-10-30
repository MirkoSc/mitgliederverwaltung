<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * Coordinates for an address from the OpenStreetMap geocoder Nominatim
 * (issue #91). Server side only. What leaves the server is the address -
 * street, postcode, town, country - and never a name or a member number.
 *
 * A structured query (street/postalcode/city/countrycodes) with limit 1,
 * the town only up to its first minus (Anschrift::ortFuerSuche()):
 * the first hit is good enough for a dot on a club's map. The User-Agent
 * names the installation, as the usage policy of Nominatim demands.
 */
final readonly class Geocoder
{
    public const string URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * The country field of the member table holds the codes of the old
     * software (licence-plate style, "D", "A", "CH"); Nominatim wants ISO
     * codes. Anything else goes to the geocoder as a free country name.
     */
    private const array LAENDER = [
        'D' => 'de', 'DE' => 'de', 'A' => 'at', 'AT' => 'at', 'CH' => 'ch', 'F' => 'fr', 'FR' => 'fr',
        'I' => 'it', 'IT' => 'it', 'NL' => 'nl', 'B' => 'be', 'BE' => 'be', 'L' => 'lu', 'LU' => 'lu',
        'DK' => 'dk', 'PL' => 'pl', 'CZ' => 'cz', 'E' => 'es', 'ES' => 'es', 'GB' => 'gb', 'UK' => 'gb',
        'H' => 'hu', 'HU' => 'hu', 'FL' => 'li', 'LI' => 'li', 'S' => 'se', 'SE' => 'se', 'N' => 'no',
        'NO' => 'no', 'P' => 'pt', 'PT' => 'pt', 'GR' => 'gr', 'SK' => 'sk', 'SLO' => 'si', 'SI' => 'si',
        'HR' => 'hr', 'RO' => 'ro', 'BG' => 'bg', 'TR' => 'tr', 'USA' => 'us', 'US' => 'us',
    ];

    public function __construct(private HttpAbruf $http, private string $userAgent)
    {
    }

    public function url(Anschrift $anschrift): string
    {
        $abfrage = ['format' => 'jsonv2', 'limit' => '1'];
        if ($anschrift->strasse !== '') {
            $abfrage['street'] = $anschrift->strasse;
        }
        if ($anschrift->plz !== '') {
            $abfrage['postalcode'] = $anschrift->plz;
        }
        if ($anschrift->ort !== '') {
            $abfrage['city'] = $anschrift->ortFuerSuche();
        }
        $code = self::LAENDER[$anschrift->land] ?? null;
        if ($code !== null) {
            $abfrage['countrycodes'] = $code;
        } else {
            $abfrage['country'] = $anschrift->land;
        }

        return self::URL . '?' . http_build_query($abfrage, '', '&', PHP_QUERY_RFC3986);
    }

    public function suchen(Anschrift $anschrift): GeoErgebnis
    {
        $antwort = $this->http->holen($this->url($anschrift), $this->userAgent);
        if (!$antwort->ok()) {
            return GeoErgebnis::fehler($antwort->status === 0 ? 'keine Antwort' : 'HTTP ' . $antwort->status);
        }

        try {
            $daten = json_decode($antwort->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return GeoErgebnis::fehler('ungültige Antwort');
        }
        if (!is_array($daten)) {
            return GeoErgebnis::fehler('ungültige Antwort');
        }
        if ($daten === []) {
            return GeoErgebnis::nichtGefunden();
        }

        $treffer = $daten[0] ?? null;
        $lat = is_array($treffer) && is_numeric($treffer['lat'] ?? null) ? (float) $treffer['lat'] : null;
        $lon = is_array($treffer) && is_numeric($treffer['lon'] ?? null) ? (float) $treffer['lon'] : null;
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
            return GeoErgebnis::fehler('ungültige Antwort');
        }

        return GeoErgebnis::gefunden($lat, $lon);
    }
}
