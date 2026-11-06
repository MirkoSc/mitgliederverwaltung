<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Support\Laender;

/**
 * Coordinates for an address from the OpenStreetMap geocoder Nominatim
 * (issue #91). Server side only. What leaves the server is the address -
 * street, postcode, town, country - and never a name or a member number.
 *
 * A structured query (street/postalcode/city/countrycodes), the town only
 * up to its first minus (Anschrift::ortFuerSuche()). A search address
 * given by hand (issue #106) goes as typed ($wortgetreu): whoever typed a
 * minus there meant it. The User-Agent names the installation, as the
 * usage policy of Nominatim demands.
 *
 * Nominatim ignores postcode and town when it does not know the street and
 * answers with the same street anywhere in the country ("Am Hang" in
 * Kürten for a street of Johannesberg, issue #108). So a hit only counts
 * when it is in the right place: its postcode is the address's postcode,
 * or - without a postcode - its town is the address's town (passt()). Up to
 * five hits with their address details are checked, not just the first.
 * Without a fitting hit a second query asks for postcode, town and country
 * only; a fitting hit there is "ungefähr" (the middle of the place). An
 * address without a street can only ever be found "ungefähr".
 *
 * Where OpenStreetMap has no house numbers, Nominatim answers with the
 * street (issue #135). When the address has a house number (a digit in the
 * street) and no fitting hit has one, the result is "Straße" - the dot
 * lies on the street, not at the house. A fitting hit with a house number
 * is preferred to one without.
 */
final readonly class Geocoder
{
    public const string URL = 'https://nominatim.openstreetmap.org/search';

    public function __construct(private HttpAbruf $http, private string $userAgent)
    {
    }

    /** Hits per query: enough to find the right one among same-named streets. */
    public const int TREFFER = 5;

    /**
     * @param bool $mitStrasse false: postcode, town and country only - the
     *        query for the approximate place (issue #108)
     */
    public function url(Anschrift $anschrift, bool $wortgetreu = false, bool $mitStrasse = true): string
    {
        $abfrage = ['format' => 'jsonv2', 'addressdetails' => '1', 'limit' => (string) self::TREFFER];
        if ($mitStrasse && $anschrift->strasse !== '') {
            $abfrage['street'] = $anschrift->strasse;
        }
        if ($anschrift->plz !== '') {
            $abfrage['postalcode'] = $anschrift->plz;
        }
        if ($anschrift->ort !== '') {
            $abfrage['city'] = self::ort($anschrift, $wortgetreu);
        }
        // The member's country is an ISO alpha-2 code, which is what Nominatim wants.
        $code = Laender::code($anschrift->land);
        if ($code !== null) {
            $abfrage['countrycodes'] = strtolower($code);
        } else {
            $abfrage['country'] = $anschrift->land;
        }

        return self::URL . '?' . http_build_query($abfrage, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The street first, then - when no hit is in the right place - the
     * place alone (issue #108). At most two requests; each one goes through
     * $jeAnfrage when given, which keeps the distance between requests
     * (Koordinatenermittlung).
     *
     * @param (\Closure(\Closure(): HttpAntwort): HttpAntwort)|null $jeAnfrage
     */
    public function suchen(Anschrift $anschrift, bool $wortgetreu = false, ?\Closure $jeAnfrage = null): GeoErgebnis
    {
        if ($anschrift->strasse !== '') {
            $genau = $this->fragen($this->url($anschrift, $wortgetreu), $anschrift, $wortgetreu, $jeAnfrage);
            if ($genau->istFehler() || $genau->status === GeoStatus::Gefunden || $genau->status === GeoStatus::Strasse) {
                return $genau;
            }
        }

        $ort = $this->fragen($this->url($anschrift, $wortgetreu, mitStrasse: false), $anschrift, $wortgetreu, $jeAnfrage);
        if ($ort->status === GeoStatus::Gefunden && $ort->lat !== null && $ort->lon !== null) {
            return GeoErgebnis::ungefaehr($ort->lat, $ort->lon);
        }

        return $ort;
    }

    /**
     * Whether one hit (with address details) lies at $anschrift: the same
     * postcode, or without a postcode the same town (town, city, village or
     * municipality; case does not count). A hit without details never fits.
     *
     * @param array<array-key, mixed> $treffer
     */
    public static function passt(array $treffer, Anschrift $anschrift, bool $wortgetreu = false): bool
    {
        $adresse = $treffer['address'] ?? null;
        if (!is_array($adresse)) {
            return false;
        }

        if ($anschrift->plz !== '') {
            $gesucht = self::plz($anschrift->plz);
            $plz = $adresse['postcode'] ?? null;
            if (!is_string($plz)) {
                return false;
            }
            // Nominatim may give several postcodes of one place: "63867;63868".
            foreach (preg_split('/[;,]/', $plz) ?: [] as $teil) {
                if (self::plz($teil) === $gesucht) {
                    return true;
                }
            }

            return false;
        }

        $gesucht = mb_strtolower(self::ort($anschrift, $wortgetreu));
        foreach (['town', 'city', 'village', 'municipality'] as $schluessel) {
            $ort = $adresse[$schluessel] ?? null;
            if (is_string($ort) && mb_strtolower(trim($ort)) === $gesucht) {
                return true;
            }
        }

        return false;
    }

    /**
     * One request: Gefunden with the coordinates of the first hit that
     * fits, NichtGefunden without one, or the failure.
     *
     * @param (\Closure(\Closure(): HttpAntwort): HttpAntwort)|null $jeAnfrage
     */
    private function fragen(string $url, Anschrift $anschrift, bool $wortgetreu, ?\Closure $jeAnfrage): GeoErgebnis
    {
        $anfrage = fn(): HttpAntwort => $this->http->holen($url, $this->userAgent);
        $antwort = $jeAnfrage === null ? $anfrage() : $jeAnfrage($anfrage);
        if (!$antwort->ok()) {
            return GeoErgebnis::fehler($antwort->status === 0 ? 'keine Antwort' : 'HTTP ' . $antwort->status);
        }

        try {
            $daten = json_decode($antwort->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return GeoErgebnis::fehler('ungültige Antwort');
        }
        if (!is_array($daten) || !array_is_list($daten)) {
            return GeoErgebnis::fehler('ungültige Antwort');
        }

        foreach ($daten as $treffer) {
            $lat = is_array($treffer) && is_numeric($treffer['lat'] ?? null) ? (float) $treffer['lat'] : null;
            $lon = is_array($treffer) && is_numeric($treffer['lon'] ?? null) ? (float) $treffer['lon'] : null;
            if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
                return GeoErgebnis::fehler('ungültige Antwort');
            }
        }
        $passend = array_values(array_filter($daten, static fn(mixed $t): bool => is_array($t) && self::passt($t, $anschrift, $wortgetreu)));
        if ($passend === []) {
            return GeoErgebnis::nichtGefunden();
        }
        if (!self::mitHausnummer($anschrift->strasse)) {
            return GeoErgebnis::gefunden((float) $passend[0]['lat'], (float) $passend[0]['lon']);
        }
        foreach ($passend as $treffer) {
            if (self::hatHausnummer($treffer)) {
                return GeoErgebnis::gefunden((float) $treffer['lat'], (float) $treffer['lon']);
            }
        }

        return GeoErgebnis::strasse((float) $passend[0]['lat'], (float) $passend[0]['lon']);
    }

    /** Whether the searched street carries a house number ("Hauptstraße 10", "Am Hang 3a"). */
    public static function mitHausnummer(string $strasse): bool
    {
        return preg_match('/\d/u', $strasse) === 1;
    }

    /**
     * Whether a hit is a house: its address details name a house number.
     *
     * @param array<array-key, mixed> $treffer
     */
    public static function hatHausnummer(array $treffer): bool
    {
        $nummer = is_array($treffer['address'] ?? null) ? ($treffer['address']['house_number'] ?? null) : null;

        return is_string($nummer) && trim($nummer) !== '';
    }

    /** The town as it goes to the geocoder - and as a hit's town is compared. */
    private static function ort(Anschrift $anschrift, bool $wortgetreu): string
    {
        return $wortgetreu ? $anschrift->ort : $anschrift->ortFuerSuche();
    }

    /** A postcode for comparing: no blanks, upper case ("a-1010" = "A-1010"). */
    private static function plz(string $plz): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $plz));
    }
}
