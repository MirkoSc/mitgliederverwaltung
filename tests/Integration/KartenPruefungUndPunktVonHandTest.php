<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Repository\GeoAnschriftRepository;
use App\Repository\KartenRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Service\Karte\Anschrift;
use App\Service\Karte\Geocoder;
use App\Service\Karte\GeoStatus;
use App\Service\Karte\HandSuche;
use App\Service\Karte\HttpAntwort;
use App\Service\Karte\Kartenmitte;
use App\Service\Karte\Koordinatenermittlung;
use App\Service\Karte\Wohnort;
use App\Service\Karte\WohnortZustand;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeHttpAbruf;
use App\Tests\Support\FakeTakt;

/**
 * Issue #108 against the real database, with a fake geocoder and a fake
 * clock: the postcode check through the whole geocoding (the case Kürten),
 * the approximate place, the point set by hand (never overwritten), "Alle
 * neu ermitteln" and migration 194.
 */
final class KartenPruefungUndPunktVonHandTest extends DatabaseTestCase
{
    private MitgliedRepository $mitglieder;

    private FakeTakt $takt;

    private \DateTimeImmutable $jetzt;

    /** @var \Closure(string): HttpAntwort */
    private \Closure $antwort;

    private FakeHttpAbruf $http;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), dirname(__DIR__, 2) . '/migrations')->migrate();
        $this->mitglieder = new MitgliedRepository($this->pdo());
        $this->takt = new FakeTakt();
        $this->jetzt = new \DateTimeImmutable('2026-10-30 12:00:00');
        $this->antwort = static fn(string $url): HttpAntwort => FakeHttpAbruf::trefferFuer($url, 48.0, 11.0);
        $takt = $this->takt;
        $this->http = new FakeHttpAbruf(function (string $url) use ($takt): HttpAntwort {
            $takt->vor(0.25);

            return ($this->antwort)($url);
        });
    }

    private function ermittlung(): Koordinatenermittlung
    {
        return new Koordinatenermittlung(
            new KartenRepository($this->pdo()),
            new GeoAnschriftRepository($this->pdo()),
            new SettingRepository($this->pdo()),
            new Geocoder($this->http, 'Mitgliederverwaltung/test (+https://verein.example.org)'),
            $this->takt,
        );
    }

    private function geo(): GeoAnschriftRepository
    {
        return new GeoAnschriftRepository($this->pdo());
    }

    private function mitglied(int $nr, string $strasse, string $plz = '12345', string $ort = 'Musterstadt'): Anschrift
    {
        $mitglied = $this->mitglieder->speichern(
            new Mitglied($nr, 'Muster' . $nr, 'Erika', strasse: $strasse, plz: $plz, ort: $ort),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied;

        return Wohnort::anschrift($mitglied);
    }

    /** The case of the issue: Nominatim answers with "Am Hang" in Kürten, the place alone fits. */
    private function kuerten(): void
    {
        $this->antwort = static fn(string $url): HttpAntwort => FakeHttpAbruf::mitStrasse($url)
            ? FakeHttpAbruf::antwort([FakeHttpAbruf::hit(51.0497, 7.2707, '51515', 'Kürten', 'town')])
            : FakeHttpAbruf::antwort([FakeHttpAbruf::hit(50.0329, 9.1424, '63867', 'Johannesberg')]);
    }

    public function testTheCaseKuertenEndsUpApproximateInJohannesbergAndIsCounted(): void
    {
        $anschrift = $this->mitglied(1, 'Am Hang 12', '63867', 'Johannesberg - OAB');
        $this->mitglied(2, 'Hauptstraße 1', '63867', 'Johannesberg');
        $this->kuerten();
        $hauptstrasse = $this->antwort;
        $this->antwort = static fn(string $url): HttpAntwort => str_contains($url, 'Hauptstra')
            ? FakeHttpAbruf::trefferFuer($url, 50.0341, 9.1399)
            : $hauptstrasse($url);

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 22.0);

        self::assertSame(1, $stand->gefunden);
        self::assertSame(1, $stand->ungefaehr);
        self::assertSame(0, $stand->offen());
        self::assertSame('1 von 2 Anschriften gefunden, 1 ungefähr', $stand->text());
        self::assertCount(3, $this->http->anfragen, 'Hauptstraße: one request; Am Hang: street, then the place');
        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertSame(GeoStatus::Ungefaehr, $eintrag['status'] ?? null);
        self::assertEqualsWithDelta(50.0329, $eintrag['lat'] ?? null, 0.000001, 'Johannesberg, not Kürten');

        // An approximate result is remembered like any other.
        $this->ermittlung()->ermitteln($this->jetzt, 10, 22.0);
        self::assertCount(3, $this->http->anfragen);
    }

    public function testBothRequestsOfOneAddressKeepTheDistance(): void
    {
        $this->mitglied(1, 'Am Hang 12', '63867', 'Johannesberg');
        $zeiten = [];
        $takt = $this->takt;
        $this->kuerten();
        $kuerten = $this->antwort;
        $this->antwort = static function (string $url) use ($takt, &$zeiten, $kuerten): HttpAntwort {
            $zeiten[] = $takt->jetzt();

            return $kuerten($url);
        };

        $this->ermittlung()->ermitteln($this->jetzt, 10, 22.0);

        self::assertCount(2, $zeiten);
        self::assertGreaterThanOrEqual(Koordinatenermittlung::ABSTAND_S - 0.0001, ($zeiten[1] - 0.25) - $zeiten[0]);
    }

    public function testASearchAddressByHandIsCheckedTheSameWay(): void
    {
        $anschrift = $this->mitglied(1, 'Gibtsnichtweg 9', '63867', 'Johannesberg');
        $this->kuerten();

        $ergebnis = $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Hang 12', '63867', 'Johannesberg', $anschrift->land), $this->jetzt);

        self::assertSame(HandSuche::Ungefaehr, $ergebnis);
        $abfragen = array_map(static function (string $url): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);

            return $abfrage;
        }, $this->http->urls());
        self::assertSame('Am Hang 12', $abfragen[0]['street'] ?? null);
        self::assertArrayNotHasKey('street', $abfragen[1]);
        self::assertSame('63867', $abfragen[1]['postalcode'] ?? null, "the search address's postcode");
        self::assertSame(GeoStatus::Ungefaehr, $this->geo()->eintrag($anschrift->hash())['status'] ?? null);

        // With another postcode in the search address the Kürten hit does not fit either.
        $this->antwort = static fn(): HttpAntwort => FakeHttpAbruf::antwort([FakeHttpAbruf::hit(51.0497, 7.2707, '51515', 'Kürten', 'town')]);
        self::assertSame(HandSuche::NichtGefunden, $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Hang 12', '63868', 'Johannesberg', $anschrift->land), $this->jetzt));
    }

    public function testAPointByHandIsSetMovedAndRemoved(): void
    {
        $anschrift = $this->mitglied(1, 'Am Hang 12', '63867', 'Johannesberg');

        $this->ermittlung()->punktSetzen($anschrift, 50.0333, 9.1450, $this->jetzt);
        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertSame(GeoStatus::VonHand, $eintrag['status'] ?? null);
        self::assertEqualsWithDelta(9.145, $eintrag['lon'] ?? null, 0.000001);
        self::assertSame(WohnortZustand::VonHand, Wohnort::aus($this->mitglieder->alle(true, $this->jetzt)[0], $this->geo(), $this->jetzt)->zustand);

        $this->ermittlung()->punktSetzen($anschrift, 50.0340, 9.1460, $this->jetzt);
        self::assertEqualsWithDelta(50.034, $this->geo()->eintrag($anschrift->hash())['lat'] ?? null, 0.000001, 'moved');
        self::assertSame([], $this->http->anfragen, 'a point by hand asks nobody');

        self::assertSame(HandSuche::Gefunden, $this->ermittlung()->punktEntfernen($anschrift, $this->jetzt));
        self::assertSame(GeoStatus::Gefunden, $this->geo()->eintrag($anschrift->hash())['status'] ?? null);
        self::assertCount(1, $this->http->anfragen, 'removing goes back to the automatic search');
    }

    public function testRemovingAPointKeepsTheSearchAddress(): void
    {
        $anschrift = $this->mitglied(1, 'Am Hang 12', '63867', 'Johannesberg');
        $this->geo()->korrekturSetzen($anschrift->hash(), 'Am Hang', '63867', 'Johannesberg', $this->jetzt);
        $this->ermittlung()->punktSetzen($anschrift, 50.03, 9.14, $this->jetzt);
        self::assertSame('Am Hang', $this->geo()->eintrag($anschrift->hash())['korrektur']['strasse'] ?? null, 'the point keeps the search address');

        $this->ermittlung()->punktEntfernen($anschrift, $this->jetzt);

        self::assertStringContainsString('street=Am%20Hang&', $this->http->urls()[0], 'asked with the search address');
        self::assertSame('Am Hang', $this->geo()->eintrag($anschrift->hash())['korrektur']['strasse'] ?? null);
    }

    public function testNeitherTheCronNorASearchNorRerunningAllOverwritesAPointByHand(): void
    {
        $anschrift = $this->mitglied(1, 'Am Hang 12', '63867', 'Johannesberg');
        $this->ermittlung()->punktSetzen($anschrift, 50.03, 9.14, $this->jetzt);

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 22.0);
        self::assertSame(1, $stand->vonHand);
        self::assertSame(0, $stand->offen());
        self::assertSame([], $this->http->anfragen, 'the cron does not ask for it');

        self::assertSame(HandSuche::PunktVonHand, $this->ermittlung()->jetztSuchen($anschrift, $this->jetzt));
        self::assertSame(HandSuche::PunktVonHand, $this->ermittlung()->korrigieren($anschrift, new Anschrift('X 1', '63867', 'Johannesberg'), $this->jetzt));
        self::assertSame(HandSuche::PunktVonHand, $this->ermittlung()->korrekturEntfernen($anschrift, $this->jetzt));
        self::assertSame([], $this->http->anfragen);
        self::assertNull($this->geo()->eintrag($anschrift->hash())['korrektur'] ?? null, 'nothing changed');

        // A result the cron was still asking for when the point was set.
        $this->geo()->speichern($anschrift->hash(), GeoStatus::Gefunden, 51.0, 7.0, $this->jetzt);
        self::assertSame(0, $this->ermittlung()->alleVergessen());

        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertSame(GeoStatus::VonHand, $eintrag['status'] ?? null);
        self::assertEqualsWithDelta(50.03, $eintrag['lat'] ?? null, 0.000001);
    }

    public function testRerunningAllForgetsOnlyTheAutomaticResults(): void
    {
        $gefunden = $this->mitglied(1, 'Hauptstraße 1');
        $ungefaehr = $this->mitglied(2, 'Am Hang 12');
        $nicht = $this->mitglied(3, 'Gibtsnichtweg 9');
        $korrigiert = $this->mitglied(4, 'Kirchweg 5');
        $hand = $this->mitglied(5, 'Bahnhofstraße 3');
        $geo = $this->geo();
        $geo->speichern($gefunden->hash(), GeoStatus::Gefunden, 50.0, 9.0, $this->jetzt);
        $geo->speichern($ungefaehr->hash(), GeoStatus::Ungefaehr, 50.1, 9.1, $this->jetzt);
        $geo->speichern($nicht->hash(), GeoStatus::NichtGefunden, null, null, $this->jetzt);
        $geo->korrekturSetzen($korrigiert->hash(), 'Kirchplatz 5', '12345', 'Musterstadt', $this->jetzt);
        $geo->speichern($korrigiert->hash(), GeoStatus::NichtGefunden, null, null, $this->jetzt);
        $geo->punktSetzen($hand->hash(), 50.2, 9.2, $this->jetzt);

        self::assertSame(4, $this->ermittlung()->alleVergessen());

        $alle = $geo->alle();
        $erwartet = [$korrigiert->hash(), $hand->hash()];
        $vorhanden = array_keys($alle);
        sort($erwartet);
        sort($vorhanden);
        self::assertSame($erwartet, $vorhanden);
        self::assertNull($alle[$korrigiert->hash()]['status']);
        self::assertSame('Kirchplatz 5', $alle[$korrigiert->hash()]['korrektur']['strasse'] ?? null);
        self::assertSame(GeoStatus::VonHand, $alle[$hand->hash()]['status']);

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 22.0);
        self::assertSame(4, $stand->gefunden);
        self::assertSame(1, $stand->vonHand);
        self::assertCount(4, $this->http->anfragen);
        self::assertStringContainsString('Kirchplatz', implode(' ', $this->http->urls()));
    }

    public function testInvalidCoordinatesAreRefused(): void
    {
        $anschrift = $this->mitglied(1, 'Am Hang 12');

        foreach ([[90.1, 9.0], [-90.1, 9.0], [50.0, 180.5], [50.0, -181.0], [NAN, 9.0], [INF, 9.0]] as [$lat, $lon]) {
            try {
                $this->ermittlung()->punktSetzen($anschrift, $lat, $lon, $this->jetzt);
                self::fail('accepted ' . $lat . '/' . $lon);
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
        self::assertNull($this->geo()->eintrag($anschrift->hash()));

        $this->ermittlung()->punktSetzen($anschrift, -90.0, 180.0, $this->jetzt);
        self::assertSame(GeoStatus::VonHand, $this->geo()->eintrag($anschrift->hash())['status'] ?? null, 'the bounds themselves are fine');
    }

    public function testMigration194ForgetsTheAutomaticResultsAndKeepsSearchAddresses(): void
    {
        $gefunden = $this->mitglied(1, 'Am Hang 12', '63867', 'Johannesberg');
        $nicht = $this->mitglied(2, 'Gibtsnichtweg 9');
        $korrigiert = $this->mitglied(3, 'Kirchweg 5');
        $geo = $this->geo();
        // As the previous version left them: "Am Hang" found in Kürten.
        $geo->speichern($gefunden->hash(), GeoStatus::Gefunden, 51.0497, 7.2707, $this->jetzt);
        $geo->speichern($nicht->hash(), GeoStatus::NichtGefunden, null, null, $this->jetzt);
        $geo->korrekturSetzen($korrigiert->hash(), 'Kirchplatz 5', '12345', 'Musterstadt', $this->jetzt);
        $geo->speichern($korrigiert->hash(), GeoStatus::Gefunden, 50.0, 9.0, $this->jetzt);

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/194_geo_anschrift_plz_pruefung.sql');
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $anweisung) {
            $this->pdo()->exec($anweisung);
        }

        $alle = $geo->alle();
        self::assertSame([$korrigiert->hash()], array_keys($alle));
        self::assertNull($alle[$korrigiert->hash()]['status']);
        self::assertNull($alle[$korrigiert->hash()]['lat']);
        self::assertSame('Kirchplatz 5', $alle[$korrigiert->hash()]['korrektur']['strasse'] ?? null);
    }

    public function testTheMiddleForSettingAPointComesFromTheDotsThereAre(): void
    {
        $ich = new Anschrift('Am Hang 12', '63867', 'Johannesberg - OAB');
        $anschriften = [];
        $koordinaten = [];
        $punkt = static function (Anschrift $a, ?GeoStatus $status, ?float $lat = null, ?float $lon = null) use (&$anschriften, &$koordinaten): void {
            $anschriften[$a->hash()] = $a;
            $koordinaten[$a->hash()] = ['status' => $status, 'lat' => $lat, 'lon' => $lon];
        };

        self::assertEquals(new Kartenmitte(Kartenmitte::DEUTSCHLAND_LAT, Kartenmitte::DEUTSCHLAND_LON, 6), Kartenmitte::fuer($ich, [], []));

        $punkt(new Anschrift('Weg 1', '80331', 'München'), GeoStatus::Gefunden, 48.0, 11.0);
        $punkt(new Anschrift('Weg 2', '80331', 'München'), GeoStatus::NichtGefunden);
        self::assertEquals(new Kartenmitte(48.0, 11.0, 11), Kartenmitte::fuer($ich, $anschriften, $koordinaten), 'all dots of the club');

        $punkt(new Anschrift('Weg 3', '63868', 'Johannesberg'), GeoStatus::Ungefaehr, 50.1, 9.2);
        self::assertEquals(new Kartenmitte(50.1, 9.2, 13), Kartenmitte::fuer($ich, $anschriften, $koordinaten), 'the same town');

        $punkt(new Anschrift('Weg 4', '63867', 'Rückersbach'), GeoStatus::VonHand, 50.0, 9.0);
        $punkt(new Anschrift('Weg 5', '63867', 'Johannesberg'), GeoStatus::Gefunden, 50.2, 9.2);
        $mitte = Kartenmitte::fuer($ich, $anschriften, $koordinaten);
        self::assertEqualsWithDelta(50.1, $mitte->lat, 0.000001, 'the same postcode first');
        self::assertEqualsWithDelta(9.1, $mitte->lon, 0.000001);
        self::assertSame(14, $mitte->zoom);
    }
}
