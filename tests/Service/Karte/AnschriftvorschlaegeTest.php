<?php

declare(strict_types=1);

namespace App\Tests\Service\Karte;

use App\Service\Karte\Anschriftvorschlaege;
use App\Service\Karte\GeoStatus;
use App\Service\Karte\HttpAntwort;
use App\Tests\Support\FakeHttpAbruf;
use PHPUnit\Framework\TestCase;

/**
 * Address suggestions from Photon (issue #127) with a fake HTTP client -
 * never a real request. The answers are built after Photon's GeoJSON.
 */
final class AnschriftvorschlaegeTest extends TestCase
{
    private const string UA = 'Mitgliederverwaltung/1.0 (+https://verein.example.org)';

    /**
     * @param array<string, mixed> $eigenschaften
     * @return array<string, mixed>
     */
    private static function feature(array $eigenschaften): array
    {
        return ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [9.14, 50.03]], 'properties' => $eigenschaften];
    }

    /** @param list<array<string, mixed>> $features */
    private static function antwort(array $features): HttpAntwort
    {
        return new HttpAntwort(200, (string) json_encode(['type' => 'FeatureCollection', 'features' => $features]), 'application/json');
    }

    /** @return array<array-key, mixed> */
    private static function abfrage(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);

        return $abfrage;
    }

    public function testTheRequestGoesToPhotonWithTextContextAndBias(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => self::antwort([]));
        $dienst = new Anschriftvorschlaege($http, self::UA, 50.03291, 9.14243);

        self::assertSame([], $dienst->suchen('Hauptstr 5', '12345', 'Musterstadt'));

        self::assertCount(1, $http->anfragen);
        $url = $http->anfragen[0]['url'];
        self::assertStringStartsWith('https://photon.komoot.io/api/?', $url);
        self::assertSame(['q' => 'Hauptstr 5, 12345 Musterstadt', 'lang' => 'de', 'limit' => '8', 'lat' => '50.0329', 'lon' => '9.1424', 'layer' => 'street'], self::abfrage($url));
        self::assertStringEndsWith('&layer=house&layer=street', $url);
        self::assertSame(self::UA, $http->anfragen[0]['userAgent']);
        self::assertSame(3, $http->anfragen[0]['timeoutS']);
    }

    public function testShortTextAsksNothing(): void
    {
        $http = new FakeHttpAbruf();
        $dienst = new Anschriftvorschlaege($http, self::UA);

        self::assertSame([], $dienst->suchen(' Ha ', '12345', 'Musterstadt'));
        self::assertSame([], $http->anfragen);
        self::assertArrayNotHasKey('lat', self::abfrage($dienst->url('Hauptstr')));
    }

    public function testHousesStreetsAndPlacesBecomeSuggestions(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => self::antwort([
            self::feature(['osm_key' => 'place', 'osm_value' => 'house', 'type' => 'house', 'street' => 'Hauptstraße', 'housenumber' => '5', 'postcode' => '63867', 'city' => 'Johannesberg', 'countrycode' => 'DE', 'name' => 'Rathaus']),
            self::feature(['osm_key' => 'highway', 'osm_value' => 'residential', 'type' => 'street', 'name' => 'Hauptstraße', 'postcode' => '63867', 'city' => 'Johannesberg', 'countrycode' => 'DE']),
            // the same house again (Photon may list a building and its entrance)
            self::feature(['type' => 'house', 'street' => 'Hauptstraße', 'housenumber' => '5', 'postcode' => '63867', 'city' => 'Johannesberg', 'countrycode' => 'de']),
            self::feature(['type' => 'street', 'osm_key' => 'highway', 'name' => 'Hauptstraße', 'postcode' => '5020', 'city' => 'Salzburg', 'countrycode' => 'AT']),
            self::feature(['type' => 'district', 'name' => 'Oberafferbach', 'district' => 'Oberafferbach', 'countrycode' => 'DE']),
            // nothing usable: neither street nor town
            self::feature(['type' => 'country', 'name' => 'Deutschland', 'countrycode' => 'DE']),
        ]));

        $vorschlaege = new Anschriftvorschlaege($http, self::UA)->suchen('Hauptstraße 5');

        self::assertSame([
            ['strasse' => 'Hauptstraße 5', 'plz' => '63867', 'ort' => 'Johannesberg', 'land' => 'DE', 'text' => 'Hauptstraße 5, 63867 Johannesberg'],
            ['strasse' => 'Hauptstraße', 'plz' => '63867', 'ort' => 'Johannesberg', 'land' => 'DE', 'text' => 'Hauptstraße, 63867 Johannesberg'],
            ['strasse' => 'Hauptstraße', 'plz' => '5020', 'ort' => 'Salzburg', 'land' => 'AT', 'text' => 'Hauptstraße, 5020 Salzburg, AT'],
            ['strasse' => '', 'plz' => '', 'ort' => 'Oberafferbach', 'land' => 'DE', 'text' => 'Oberafferbach'],
        ], $vorschlaege);
    }

    public function testAtMostFiveSuggestions(): void
    {
        $features = [];
        for ($i = 1; $i <= 8; ++$i) {
            $features[] = self::feature(['type' => 'house', 'street' => 'Weg', 'housenumber' => (string) $i, 'city' => 'Musterstadt', 'countrycode' => 'DE']);
        }

        self::assertCount(Anschriftvorschlaege::ANZAHL, Anschriftvorschlaege::auswerten((string) json_encode(['features' => $features])));
    }

    public function testFailuresAndOddAnswersGiveAnEmptyList(): void
    {
        foreach ([new HttpAntwort(0), new HttpAntwort(500, '{}'), new HttpAntwort(429, '{"features":[]}')] as $antwort) {
            $http = new FakeHttpAbruf(static fn(): HttpAntwort => $antwort);
            self::assertSame([], new Anschriftvorschlaege($http, self::UA)->suchen('Hauptstraße'));
        }

        foreach (['', 'kein json', '[]', '{"features":"x"}', '{"features":{"a":1}}', '{"features":[1,null,{"properties":"x"}]}', str_repeat('[', 40) . str_repeat(']', 40)] as $body) {
            self::assertSame([], Anschriftvorschlaege::auswerten($body), $body);
        }
    }

    public function testValuesAreCleanedAndOddCountryCodesDropped(): void
    {
        $vorschlaege = Anschriftvorschlaege::auswerten((string) json_encode(['features' => [
            self::feature(['type' => 'house', 'street' => " Am\tHang ", 'housenumber' => 3, 'postcode' => ['x'], 'city' => 'Johannesberg', 'countrycode' => 'DEU']),
        ]]));

        self::assertSame([['strasse' => 'Am Hang 3', 'plz' => '', 'ort' => 'Johannesberg', 'land' => '', 'text' => 'Am Hang 3, Johannesberg']], $vorschlaege);
    }

    public function testTheBiasIsTheMiddleOfThePointsOnTheMap(): void
    {
        self::assertNull(Anschriftvorschlaege::mitte([]));
        self::assertSame([50.0, 9.0], Anschriftvorschlaege::mitte([
            'a' => ['status' => GeoStatus::Gefunden, 'lat' => 49.0, 'lon' => 8.0, 'korrektur' => null],
            'b' => ['status' => GeoStatus::VonHand, 'lat' => 51.0, 'lon' => 10.0, 'korrektur' => null],
            'c' => ['status' => GeoStatus::NichtGefunden, 'lat' => null, 'lon' => null, 'korrektur' => null],
        ]));
    }
}
