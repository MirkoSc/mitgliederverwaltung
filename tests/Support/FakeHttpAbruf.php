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

    /** A geocoder answer with one hit. */
    public static function treffer(float $lat, float $lon): HttpAntwort
    {
        return new HttpAntwort(200, (string) json_encode([['lat' => (string) $lat, 'lon' => (string) $lon, 'display_name' => 'x']]), 'application/json');
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
