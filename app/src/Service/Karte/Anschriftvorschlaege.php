<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Dienste;
use App\Repository\GeoAnschriftRepository;
use App\Support\Laender;

/**
 * Address suggestions while typing in the member form (issue #127), from
 * the OpenStreetMap search Photon (komoot) - Nominatim forbids
 * autocomplete in its usage policy. Server side only, like the geocoder
 * (docs/spec/01-plattform.md section 8): the browser asks this
 * installation, never a foreign host.
 *
 * What leaves the server: the typed street text, postcode and town of the
 * form - never a name or a member number - and, as a bias towards the
 * club's area, the middle of the members' points on the map. The URL is
 * fixed, the input only ever becomes query parameters. A short timeout
 * (a person waits while typing); any failure is an empty list, nothing is
 * logged - least of all the typed text.
 */
final readonly class Anschriftvorschlaege
{
    public const string URL = 'https://photon.komoot.io/api/';

    /** Suggestions shown at most. */
    public const int ANZAHL = 5;

    /** Hits asked for: a few more than shown, duplicates fall away. */
    private const int TREFFER = 8;

    /** Seconds a person waits at most for the list. */
    public const int TIMEOUT_S = 3;

    /** Below this, a search makes no sense (and is not sent). */
    public const int MIN_ZEICHEN = 3;

    /** Same as the field street of the member form. */
    public const int MAX_ZEICHEN = 150;

    public function __construct(
        private HttpAbruf $http,
        private string $userAgent,
        private ?float $biasLat = null,
        private ?float $biasLon = null,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static function (Dienste $d): self {
            $mitte = new GeoAnschriftRepository($d->pdo())->mitte();

            return new self(KartenDienste::http($d), KartenDienste::userAgent($d), $mitte[0] ?? null, $mitte[1] ?? null);
        });
    }

    /**
     * The search text: what was typed for the street, then postcode and
     * town as context. Null when the street text is too short.
     */
    public static function suchtext(string $strasse, string $plz = '', string $ort = ''): ?string
    {
        $strasse = self::kuerzen($strasse);
        if (mb_strlen($strasse) < self::MIN_ZEICHEN) {
            return null;
        }
        $ort = trim(self::kuerzen($plz, 10) . ' ' . self::kuerzen($ort, 100));

        return $ort === '' ? $strasse : $strasse . ', ' . $ort;
    }

    public function url(string $suchtext): string
    {
        $abfrage = ['q' => $suchtext, 'lang' => 'de', 'limit' => (string) self::TREFFER];
        if ($this->biasLat !== null && $this->biasLon !== null && Koordinatenermittlung::gueltig($this->biasLat, $this->biasLon)) {
            $abfrage['lat'] = sprintf('%.4F', $this->biasLat);
            $abfrage['lon'] = sprintf('%.4F', $this->biasLon);
        }

        // Photon takes the layer parameter more than once; http_build_query cannot.
        return self::URL . '?' . http_build_query($abfrage, '', '&', PHP_QUERY_RFC3986) . '&layer=house&layer=street';
    }

    /**
     * Suggestions for what was typed: at most ANZAHL, each with street and
     * house number, postcode, town, country (ISO 3166 alpha-2, upper case)
     * and the text the list shows (the country by name, only abroad). Empty for a short text, on any failure
     * and when nothing fits.
     *
     * @return list<array{strasse: string, plz: string, ort: string, land: string, text: string}>
     */
    public function suchen(string $strasse, string $plz = '', string $ort = ''): array
    {
        $suchtext = self::suchtext($strasse, $plz, $ort);
        if ($suchtext === null) {
            return [];
        }

        $antwort = $this->http->holen($this->url($suchtext), $this->userAgent, self::TIMEOUT_S);

        return $antwort->ok() ? self::auswerten($antwort->body) : [];
    }

    /**
     * Reads a Photon answer (GeoJSON FeatureCollection) defensively:
     * anything unexpected is skipped, broken JSON is an empty list.
     *
     * @return list<array{strasse: string, plz: string, ort: string, land: string, text: string}>
     */
    public static function auswerten(string $body): array
    {
        try {
            $daten = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $features = is_array($daten) ? ($daten['features'] ?? null) : null;
        if (!is_array($features) || !array_is_list($features)) {
            return [];
        }

        $vorschlaege = [];
        foreach ($features as $feature) {
            $eigenschaften = is_array($feature) ? ($feature['properties'] ?? null) : null;
            if (!is_array($eigenschaften)) {
                continue;
            }
            $vorschlag = self::vorschlag($eigenschaften);
            if ($vorschlag === null) {
                continue;
            }
            $schluessel = mb_strtolower($vorschlag['text']);
            $vorschlaege[$schluessel] ??= $vorschlag;
            if (count($vorschlaege) === self::ANZAHL) {
                break;
            }
        }

        return array_values($vorschlaege);
    }

    /**
     * One hit as a suggestion, or null without street and town.
     *
     * @param array<array-key, mixed> $p the properties of a feature
     * @return array{strasse: string, plz: string, ort: string, land: string, text: string}|null
     */
    private static function vorschlag(array $p): ?array
    {
        $typ = self::text($p, 'type');
        $name = self::text($p, 'name');
        $strassenname = self::text($p, 'street');
        // A street itself carries its name in "name", not in "street".
        if ($strassenname === '' && ($typ === 'street' || self::text($p, 'osm_key') === 'highway')) {
            $strassenname = $name;
        }
        $hausnummer = self::text($p, 'housenumber');
        $strasse = $strassenname === '' ? '' : trim($strassenname . ' ' . $hausnummer);

        $ort = self::text($p, 'city');
        if ($ort === '' && in_array($typ, ['city', 'town', 'village'], true)) {
            $ort = $name;
        }
        if ($ort === '') {
            $ort = self::text($p, 'district') !== '' ? self::text($p, 'district') : self::text($p, 'locality');
        }
        if ($strasse === '' && $ort === '') {
            return null;
        }

        $plz = self::text($p, 'postcode');
        $land = strtoupper(self::text($p, 'countrycode'));
        if (preg_match('/^[A-Z]{2}$/', $land) !== 1) {
            $land = '';
        }

        $teile = array_filter([$strasse, trim($plz . ' ' . $ort), $land !== Laender::STANDARD ? Laender::name($land) : ''], static fn(string $t): bool => $t !== '');

        return [
            'strasse' => mb_substr($strasse, 0, self::MAX_ZEICHEN),
            'plz' => mb_substr($plz, 0, 10),
            'ort' => mb_substr($ort, 0, 100),
            'land' => $land,
            'text' => implode(', ', $teile),
        ];
    }

    /**
     * A string property, trimmed, without control characters; anything
     * else is ''.
     *
     * @param array<array-key, mixed> $p
     */
    private static function text(array $p, string $schluessel): string
    {
        $wert = $p[$schluessel] ?? null;
        if (!is_string($wert) && !is_int($wert)) {
            return '';
        }

        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $wert));
    }

    private static function kuerzen(string $text, int $laenge = self::MAX_ZEICHEN): string
    {
        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $laenge));
    }
}
