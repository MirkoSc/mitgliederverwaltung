<?php

declare(strict_types=1);

namespace App\Tests\Service\Karte;

use App\Service\Karte\Anschrift;
use App\Service\Karte\Geocoder;
use App\Service\Karte\GeoStatus;
use App\Service\Karte\HttpAntwort;
use App\Tests\Support\FakeHttpAbruf;
use PHPUnit\Framework\TestCase;

final class GeocoderTest extends TestCase
{
    public function testTheRequestCarriesTheAddressAndTheInstallationButNoName(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::treffer(48.1, 11.5));
        $geocoder = new Geocoder($http, 'Mitgliederverwaltung/1.0 (+https://verein.example.org)');

        $geocoder->suchen(new Anschrift('Hauptstraße 1', '12345', 'Musterstadt'));

        self::assertCount(1, $http->anfragen);
        $url = $http->anfragen[0]['url'];
        self::assertStringStartsWith(Geocoder::URL . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);
        self::assertSame([
            'format' => 'jsonv2',
            'limit' => '1',
            'street' => 'Hauptstraße 1',
            'postalcode' => '12345',
            'city' => 'Musterstadt',
            'countrycodes' => 'de',
        ], $abfrage);
        self::assertSame('Mitgliederverwaltung/1.0 (+https://verein.example.org)', $http->anfragen[0]['userAgent']);
    }

    public function testAnUnknownCountryCodeGoesAsCountryName(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer());
        new Geocoder($http, 'ua')->suchen(new Anschrift('', '', 'Vaduz', 'Liechtenstein'));

        parse_str((string) parse_url($http->urls()[0], PHP_URL_QUERY), $abfrage);
        self::assertSame('LIECHTENSTEIN', $abfrage['country']);
        self::assertArrayNotHasKey('countrycodes', $abfrage);
        self::assertArrayNotHasKey('street', $abfrage);
    }

    public function testFoundNotFoundAndFailure(): void
    {
        $antworten = [
            FakeHttpAbruf::treffer(48.137154, 11.576124),
            FakeHttpAbruf::keinTreffer(),
            new HttpAntwort(429, 'Too many requests'),
            new HttpAntwort(0),
            new HttpAntwort(200, '<html>'),
            new HttpAntwort(200, '[{"lat":"x","lon":"1"}]'),
        ];
        $geocoder = new Geocoder(new FakeHttpAbruf(static function () use (&$antworten): HttpAntwort {
            return array_shift($antworten);
        }), 'ua');
        $anschrift = new Anschrift('Marienplatz 1', '80331', 'München');

        $gefunden = $geocoder->suchen($anschrift);
        self::assertSame(GeoStatus::Gefunden, $gefunden->status);
        self::assertEqualsWithDelta(48.137154, $gefunden->lat, 0.000001);
        self::assertEqualsWithDelta(11.576124, $gefunden->lon, 0.000001);

        self::assertSame(GeoStatus::NichtGefunden, $geocoder->suchen($anschrift)->status);

        $fehler = $geocoder->suchen($anschrift);
        self::assertTrue($fehler->istFehler());
        self::assertSame('HTTP 429', $fehler->fehler);
        self::assertSame('keine Antwort', $geocoder->suchen($anschrift)->fehler);
        self::assertSame('ungültige Antwort', $geocoder->suchen($anschrift)->fehler);
        self::assertSame('ungültige Antwort', $geocoder->suchen($anschrift)->fehler);
    }
}
