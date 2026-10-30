<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Karte\HttpAbruf;
use App\Service\Karte\HttpAntwort;

/**
 * The external services of the map in tests (issue #91): answers from a
 * callback, every request recorded. Nothing ever leaves the test.
 */
final class FakeHttpAbruf implements HttpAbruf
{
    /** @var list<array{url: string, userAgent: string}> */
    public array $anfragen = [];

    /** @var \Closure(string): HttpAntwort */
    private \Closure $antwort;

    /**
     * @param (\Closure(string): HttpAntwort)|null $antwort default: 404
     */
    public function __construct(?\Closure $antwort = null)
    {
        $this->antwort = $antwort ?? static fn(string $url): HttpAntwort => new HttpAntwort(404);
    }

    public function holen(string $url, string $userAgent): HttpAntwort
    {
        $this->anfragen[] = ['url' => $url, 'userAgent' => $userAgent];

        return ($this->antwort)($url);
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_column($this->anfragen, 'url');
    }

    /**
     * A geocoder answer with one hit at postcode $plz in $ort (issue #108:
     * only a hit with the right postcode counts). Without a postcode the
     * hit carries none - it never fits an address with a postcode.
     */
    public static function treffer(float $lat, float $lon, string $plz = '', string $ort = ''): HttpAntwort
    {
        return self::antwort([self::hit($lat, $lon, $plz, $ort)]);
    }

    /**
     * A hit exactly where the request asked: postcode and town of the
     * query echoed back - for tests about something else than the check.
     */
    public static function trefferFuer(string $url, float $lat, float $lon): HttpAntwort
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);

        return self::treffer($lat, $lon, is_string($abfrage['postalcode'] ?? null) ? $abfrage['postalcode'] : '', is_string($abfrage['city'] ?? null) ? $abfrage['city'] : '');
    }

    /**
     * One hit as Nominatim gives it with addressdetails=1.
     *
     * @return array<string, mixed>
     */
    public static function hit(float $lat, float $lon, string $plz = '', string $ort = '', string $ortArt = 'village'): array
    {
        $adresse = ['country' => 'Deutschland', 'country_code' => 'de'];
        if ($plz !== '') {
            $adresse['postcode'] = $plz;
        }
        if ($ort !== '') {
            $adresse[$ortArt] = $ort;
        }

        return ['lat' => (string) $lat, 'lon' => (string) $lon, 'display_name' => 'x', 'address' => $adresse];
    }

    /** @param list<array<string, mixed>> $hits */
    public static function antwort(array $hits): HttpAntwort
    {
        return new HttpAntwort(200, (string) json_encode($hits), 'application/json');
    }

    /** Whether the request asked for a street (the first query, not the one for the place alone). */
    public static function mitStrasse(string $url): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);

        return isset($abfrage['street']);
    }

    /** A geocoder answer without a hit. */
    public static function keinTreffer(): HttpAntwort
    {
        return new HttpAntwort(200, '[]', 'application/json');
    }

    /** A tiny but real PNG (1x1). */
    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }
}
