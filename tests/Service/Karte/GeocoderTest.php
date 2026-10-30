<?php

declare(strict_types=1);

namespace App\Tests\Service\Karte;

use App\Service\Karte\Anschrift;
use App\Service\Karte\Geocoder;
use App\Service\Karte\GeoStatus;
use App\Service\Karte\HttpAntwort;
use App\Tests\Support\FakeHttpAbruf;
use PHPUnit\Framework\TestCase;

/**
 * The geocoder with a fake HTTP client - never a real request. Since issue
 * #108 a hit only counts in the right place (postcode, else town), and
 * without one the place alone is asked: "ungefähr".
 */
final class GeocoderTest extends TestCase
{
    /** Kürten, where Nominatim put "Am Hang" of Johannesberg (checked with real requests, issue #108). */
    private const array KUERTEN = [51.0497, 7.2707];

    /** The middle of Johannesberg (63867). */
    private const array JOHANNESBERG = [50.0329, 9.1424];

    /**
     * @return array<array-key, mixed>
     */
    private static function abfrage(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);

        return $abfrage;
    }

    public function testTheRequestCarriesTheAddressAndTheInstallationButNoName(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::treffer(48.1, 11.5, '12345', 'Musterstadt'));
        $geocoder = new Geocoder($http, 'Mitgliederverwaltung/1.0 (+https://verein.example.org)');

        $ergebnis = $geocoder->suchen(new Anschrift('Hauptstraße 1', '12345', 'Musterstadt'));

        self::assertSame(GeoStatus::Gefunden, $ergebnis->status);
        self::assertCount(1, $http->anfragen, 'a fitting hit: no second request');
        $url = $http->anfragen[0]['url'];
        self::assertStringStartsWith(Geocoder::URL . '?', $url);
        self::assertSame([
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'limit' => (string) Geocoder::TREFFER,
            'street' => 'Hauptstraße 1',
            'postalcode' => '12345',
            'city' => 'Musterstadt',
            'countrycodes' => 'de',
        ], self::abfrage($url));
        self::assertSame('Mitgliederverwaltung/1.0 (+https://verein.example.org)', $http->anfragen[0]['userAgent']);
    }

    public function testTheTownGoesOnlyUpToItsFirstMinus(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer());
        new Geocoder($http, 'ua')->suchen(new Anschrift('Hauptstraße 1', '12345', 'Musterort - OAB'));

        $abfrage = self::abfrage($http->urls()[0]);
        self::assertSame('Musterort', $abfrage['city']);
        self::assertSame('12345', $abfrage['postalcode']);
    }

    public function testAnUnknownCountryCodeGoesAsCountryName(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer());
        new Geocoder($http, 'ua')->suchen(new Anschrift('', '', 'Vaduz', 'Liechtenstein'));

        self::assertCount(1, $http->anfragen, 'without a street there is only the query for the place');
        $abfrage = self::abfrage($http->urls()[0]);
        self::assertSame('LIECHTENSTEIN', $abfrage['country']);
        self::assertArrayNotHasKey('countrycodes', $abfrage);
        self::assertArrayNotHasKey('street', $abfrage);
    }

    /**
     * The case of issue #108: "Am Hang" is missing in OpenStreetMap in
     * Johannesberg, Nominatim answers with "Am Hang" in 51515 Kürten. That
     * hit is thrown away; the place alone is found - "ungefähr".
     */
    public function testAHitWithAnotherPostcodeIsDiscardedAndThePlaceIsTheApproximateDot(): void
    {
        [$kLat, $kLon] = self::KUERTEN;
        [$jLat, $jLon] = self::JOHANNESBERG;
        $http = new FakeHttpAbruf(static fn(string $url): HttpAntwort => FakeHttpAbruf::mitStrasse($url)
            ? FakeHttpAbruf::antwort([FakeHttpAbruf::hit($kLat, $kLon, '51515', 'Kürten', 'town')])
            : FakeHttpAbruf::antwort([FakeHttpAbruf::hit($jLat, $jLon, '63867', 'Johannesberg')]));

        $ergebnis = new Geocoder($http, 'ua')->suchen(new Anschrift('Am Hang 12', '63867', 'Johannesberg - OAB'));

        self::assertSame(GeoStatus::Ungefaehr, $ergebnis->status);
        self::assertEqualsWithDelta($jLat, $ergebnis->lat, 0.000001);
        self::assertEqualsWithDelta($jLon, $ergebnis->lon, 0.000001);
        self::assertCount(2, $http->anfragen);
        $zweite = self::abfrage($http->urls()[1]);
        self::assertArrayNotHasKey('street', $zweite, 'the second query asks for the place alone');
        self::assertSame('63867', $zweite['postalcode']);
        self::assertSame('Johannesberg', $zweite['city']);
        self::assertSame('de', $zweite['countrycodes']);
    }

    public function testTheHitWithTheRightPostcodeIsTakenEvenWhenItIsNotTheFirst(): void
    {
        [$kLat, $kLon] = self::KUERTEN;
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::antwort([
            FakeHttpAbruf::hit($kLat, $kLon, '51515', 'Kürten', 'town'),
            FakeHttpAbruf::hit(50.0341, 9.1399, '63867', 'Johannesberg'),
        ]));

        $ergebnis = new Geocoder($http, 'ua')->suchen(new Anschrift('Hauptstraße 1', '63867', 'Johannesberg'));

        self::assertSame(GeoStatus::Gefunden, $ergebnis->status);
        self::assertEqualsWithDelta(50.0341, $ergebnis->lat, 0.000001);
        self::assertCount(1, $http->anfragen);
    }

    public function testWhenTheApproximateQueryDoesNotFitEitherItIsNotFound(): void
    {
        [$kLat, $kLon] = self::KUERTEN;
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::antwort([FakeHttpAbruf::hit($kLat, $kLon, '51515', 'Kürten', 'town')]));

        $ergebnis = new Geocoder($http, 'ua')->suchen(new Anschrift('Am Hang 12', '63867', 'Johannesberg'));

        self::assertSame(GeoStatus::NichtGefunden, $ergebnis->status);
        self::assertNull($ergebnis->lat);
        self::assertCount(2, $http->anfragen);
    }

    public function testAHitWithoutAddressDetailsNeverFits(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => new HttpAntwort(200, '[{"lat":"50.1","lon":"9.1"}]'));

        self::assertSame(GeoStatus::NichtGefunden, new Geocoder($http, 'ua')->suchen(new Anschrift('Hauptstraße 1', '63867', 'Johannesberg'))->status);
    }

    public function testWithoutPostcodeTheTownDecidesWithoutCase(): void
    {
        $anschrift = new Anschrift('Hauptstraße 1', '', 'Johannesberg - OAB');

        self::assertTrue(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '63867', 'JOHANNESBERG'), $anschrift));
        self::assertTrue(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '', 'johannesberg', 'municipality'), $anschrift));
        self::assertTrue(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '', 'Johannesberg', 'city'), $anschrift));
        self::assertFalse(Geocoder::passt(FakeHttpAbruf::hit(51.0, 7.2, '51515', 'Kürten', 'town'), $anschrift));
        self::assertFalse(Geocoder::passt(['lat' => '50', 'lon' => '9'], $anschrift));

        // A search address goes as typed - and is compared as typed.
        $suche = new Anschrift('Hauptstraße 1', '', 'Musterstadt-Nord');
        self::assertTrue(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '', 'Musterstadt-Nord', 'town'), $suche, wortgetreu: true));
        self::assertFalse(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '', 'Musterstadt-Nord', 'town'), $suche));
    }

    public function testThePostcodeIsComparedWithoutBlanksAndInLists(): void
    {
        $anschrift = new Anschrift('Hauptstraße 1', '63867', 'Johannesberg');

        self::assertTrue(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '63867;63868', 'Johannesberg'), $anschrift));
        self::assertTrue(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, ' 63867 ', 'Johannesberg'), $anschrift));
        self::assertFalse(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '63868', 'Johannesberg'), $anschrift), 'the right town with another postcode does not fit');
        self::assertFalse(Geocoder::passt(FakeHttpAbruf::hit(50.0, 9.1, '', 'Johannesberg'), $anschrift), 'with a postcode, a hit without one does not fit');
    }

    public function testFoundNotFoundAndFailure(): void
    {
        $antworten = [
            FakeHttpAbruf::treffer(48.137154, 11.576124, '80331', 'München'),
            FakeHttpAbruf::keinTreffer(),
            FakeHttpAbruf::keinTreffer(),
            new HttpAntwort(429, 'Too many requests'),
            new HttpAntwort(0),
            new HttpAntwort(200, '<html>'),
            new HttpAntwort(200, '[{"lat":"x","lon":"1"}]'),
            new HttpAntwort(200, '{"lat":"48","lon":"11"}'),
            // The street does not fit, then the place answers with a failure.
            FakeHttpAbruf::keinTreffer(),
            new HttpAntwort(503),
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
        self::assertSame('ungültige Antwort', $geocoder->suchen($anschrift)->fehler, 'an object instead of a list');
        self::assertSame('HTTP 503', $geocoder->suchen($anschrift)->fehler);
        self::assertSame([], $antworten);
    }

    public function testEveryRequestGoesThroughTheGivenWrapper(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer());
        $aufrufe = 0;
        new Geocoder($http, 'ua')->suchen(
            new Anschrift('Am Hang 12', '63867', 'Johannesberg'),
            jeAnfrage: static function (\Closure $anfrage) use (&$aufrufe): HttpAntwort {
                $aufrufe++;

                return $anfrage();
            },
        );

        self::assertSame(2, $aufrufe);
        self::assertCount(2, $http->anfragen);
    }
}
