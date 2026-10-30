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
use App\Service\Karte\Koordinatenermittlung;
use App\Service\Karte\Wohnort;
use App\Service\Karte\WohnortZustand;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeHttpAbruf;
use App\Tests\Support\FakeTakt;

/**
 * One address on demand and the search address for the map (issue #106),
 * against the real database with a fake geocoder and a fake clock: what
 * the geocoder gets, what is stored, how the automatic run uses it, the
 * lock and the rest after a failure.
 */
final class KartenSucheVonHandTest extends DatabaseTestCase
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

    private function mitglied(int $nr, string $strasse, string $plz = '12345', string $ort = 'Musterstadt - OAB'): Mitglied
    {
        return $this->mitglieder->speichern(
            new Mitglied($nr, 'Muster' . $nr, 'Erika', strasse: $strasse, plz: $plz, ort: $ort),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied;
    }

    private function geo(): GeoAnschriftRepository
    {
        return new GeoAnschriftRepository($this->pdo());
    }

    /**
     * The query of the last request to the geocoder.
     *
     * @return array<array-key, mixed>
     */
    private function letzteAbfrage(): array
    {
        $urls = $this->http->urls();
        self::assertNotEmpty($urls);
        parse_str((string) parse_url((string) end($urls), PHP_URL_QUERY), $abfrage);

        return $abfrage;
    }

    public function testACorrectionIsSentAsTypedAndFound(): void
    {
        $mitglied = $this->mitglied(1, 'Hauptstr. 1');
        $anschrift = Wohnort::anschrift($mitglied);
        $this->antwort = static fn(string $url): HttpAntwort => FakeHttpAbruf::keinTreffer();
        self::assertSame(HandSuche::NichtGefunden, $this->ermittlung()->jetztSuchen($anschrift, $this->jetzt));
        self::assertSame('Musterstadt', $this->letzteAbfrage()['city'], 'the address itself: town up to the minus');

        $this->antwort = static fn(string $url): HttpAntwort => str_contains($url, 'Am%20Markt')
            ? FakeHttpAbruf::trefferFuer($url, 49.5, 9.25)
            : FakeHttpAbruf::keinTreffer();
        $ergebnis = $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Markt 3', '12346', 'Musterstadt-Nord', $anschrift->land), $this->jetzt);

        self::assertSame(HandSuche::Gefunden, $ergebnis);
        $abfrage = $this->letzteAbfrage();
        self::assertSame('Am Markt 3', $abfrage['street']);
        self::assertSame('12346', $abfrage['postalcode']);
        self::assertSame('Musterstadt-Nord', $abfrage['city'], 'a search address goes as typed');
        self::assertSame('de', $abfrage['countrycodes']);

        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertSame(GeoStatus::Gefunden, $eintrag['status'] ?? null);
        self::assertEqualsWithDelta(49.5, $eintrag['lat'] ?? 0.0, 0.000001);
        self::assertSame(['strasse' => 'Am Markt 3', 'plz' => '12346', 'ort' => 'Musterstadt-Nord'], $eintrag['korrektur'] ?? null);

        // The member's own address stays as it is.
        $gespeichert = $this->mitglieder->finde((int) $mitglied->id);
        self::assertSame(['Hauptstr. 1', '12345', 'Musterstadt - OAB'], [$gespeichert?->strasse, $gespeichert?->plz, $gespeichert?->ort]);
        self::assertSame(1, $gespeichert?->version, 'no new version of the member');

        $wohnort = Wohnort::aus($mitglied, $this->geo(), $this->jetzt);
        self::assertSame(WohnortZustand::Gefunden, $wohnort->zustand);
        self::assertSame('Am Markt 3', $wohnort->vorbelegung()->strasse);
    }

    public function testACorrectionThatIsStillNotFoundIsKept(): void
    {
        $anschrift = Wohnort::anschrift($this->mitglied(1, 'Gibtsnichtweg 9'));
        $this->antwort = static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer();

        $ergebnis = $this->ermittlung()->korrigieren($anschrift, new Anschrift('Gibtsauchnichtweg 9', '12345', 'Musterstadt'), $this->jetzt);

        self::assertSame(HandSuche::NichtGefunden, $ergebnis);
        self::assertCount(2, $this->http->anfragen, 'the street, then the place alone (issue #108)');
        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertSame(GeoStatus::NichtGefunden, $eintrag['status'] ?? null);
        self::assertSame('Gibtsauchnichtweg 9', $eintrag['korrektur']['strasse'] ?? null, 'the form shows what was typed');

        // The automatic run does not ask again: "not found" is remembered.
        $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);
        self::assertCount(2, $this->http->anfragen);
    }

    public function testRemovingTheCorrectionSearchesWithTheAddressAgain(): void
    {
        $anschrift = Wohnort::anschrift($this->mitglied(1, 'Hauptstraße 1'));
        $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Markt 3', '12345', 'Musterstadt'), $this->jetzt);

        $ergebnis = $this->ermittlung()->korrekturEntfernen($anschrift, $this->jetzt);

        self::assertSame(HandSuche::Gefunden, $ergebnis);
        self::assertCount(2, $this->http->anfragen);
        self::assertSame('Hauptstraße 1', $this->letzteAbfrage()['street']);
        self::assertSame('Musterstadt', $this->letzteAbfrage()['city']);
        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertSame(GeoStatus::Gefunden, $eintrag['status'] ?? null);
        self::assertNull($eintrag['korrektur'] ?? null);
    }

    public function testTheAutomaticRunUsesTheCorrection(): void
    {
        $mitglied = $this->mitglied(1, 'Hauptstraße 1');
        $anschrift = Wohnort::anschrift($mitglied);
        // A search address saved while the service rested: not asked yet.
        new SettingRepository($this->pdo())->set(Koordinatenermittlung::SETTING_PAUSE_BIS, $this->jetzt->modify('+5 minutes')->format('Y-m-d H:i:s'));

        self::assertSame(HandSuche::Ruht, $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Markt 3', '12345', 'Musterstadt'), $this->jetzt));
        self::assertSame([], $this->http->anfragen, 'the service rests - nobody is asked');
        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertNull($eintrag['status'] ?? null);
        self::assertSame('Am Markt 3', $eintrag['korrektur']['strasse'] ?? null);
        self::assertSame(WohnortZustand::Offen, Wohnort::aus($mitglied, $this->geo(), $this->jetzt)->zustand);

        $stand = $this->ermittlung()->ermitteln($this->jetzt->modify('+20 minutes'), 10, 12.0);

        self::assertSame(1, $stand->gefunden);
        self::assertSame('Am Markt 3', $this->letzteAbfrage()['street'], 'the cron asks with the search address');
        self::assertSame(GeoStatus::Gefunden, $this->geo()->eintrag($anschrift->hash())['status'] ?? null);
        self::assertSame('Am Markt 3', $this->geo()->eintrag($anschrift->hash())['korrektur']['strasse'] ?? null, 'and keeps it');
    }

    public function testAChangedAddressIgnoresTheOldCorrection(): void
    {
        $mitglied = $this->mitglied(1, 'Hauptstraße 1');
        $alt = Wohnort::anschrift($mitglied);
        $this->ermittlung()->korrigieren($alt, new Anschrift('Am Markt 3', '12345', 'Musterstadt'), $this->jetzt);

        $umgezogen = $this->mitglieder->speichern($mitglied->mit(['strasse' => 'Neuweg 5']), VersionsAnlass::geaendert(), 1)->mitglied;
        $neu = Wohnort::anschrift($umgezogen);
        self::assertSame(WohnortZustand::Offen, Wohnort::aus($umgezogen, $this->geo(), $this->jetzt)->zustand);
        self::assertNull(Wohnort::aus($umgezogen, $this->geo(), $this->jetzt)->korrektur);

        $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);

        self::assertSame('Neuweg 5', $this->letzteAbfrage()['street'], 'the new address goes as it is');
        self::assertNull($this->geo()->eintrag($neu->hash())['korrektur'] ?? null);
        self::assertNull($this->geo()->eintrag($alt->hash()), 'the old row with its correction is cleaned up');
    }

    public function testOneSearchKeepsTheDistanceToTheLastRequest(): void
    {
        $anschrift = Wohnort::anschrift($this->mitglied(1, 'Hauptstraße 1'));
        new SettingRepository($this->pdo())->set(Koordinatenermittlung::SETTING_LETZTE_ANFRAGE, $this->takt->jetzt() - 0.2);

        self::assertSame(HandSuche::Gefunden, $this->ermittlung()->jetztSuchen($anschrift, $this->jetzt));

        self::assertCount(1, $this->takt->pausen);
        self::assertEqualsWithDelta(Koordinatenermittlung::ABSTAND_S - 0.2, $this->takt->pausen[0], 0.0001);
    }

    public function testAFailureKeepsTheCorrectionAndPausesTheGeocoding(): void
    {
        $anschrift = Wohnort::anschrift($this->mitglied(1, 'Hauptstraße 1'));
        $this->antwort = static fn(): HttpAntwort => new HttpAntwort(503);

        $ergebnis = $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Markt 3', '12345', 'Musterstadt'), $this->jetzt);

        self::assertSame(HandSuche::Fehler, $ergebnis);
        $eintrag = $this->geo()->eintrag($anschrift->hash());
        self::assertNull($eintrag['status'] ?? null);
        self::assertSame('Am Markt 3', $eintrag['korrektur']['strasse'] ?? null);
        self::assertNotSame('', new SettingRepository($this->pdo())->text(Koordinatenermittlung::SETTING_PAUSE_BIS));

        // While it rests, "Jetzt suchen" asks nobody.
        self::assertSame(HandSuche::Ruht, $this->ermittlung()->jetztSuchen($anschrift, $this->jetzt->modify('+1 minute')));
        self::assertCount(1, $this->http->anfragen);
    }

    public function testWhileAnotherRequestHoldsTheLockNothingChanges(): void
    {
        $anschrift = Wohnort::anschrift($this->mitglied(1, 'Hauptstraße 1'));
        /** @var array{host: string, port: int, user: string, password: string} $db */
        $db = self::configData()['db'];
        $zweite = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], self::dbName()),
            $db['user'],
            $db['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $sperre = new GeoAnschriftRepository($zweite);
        self::assertTrue($sperre->sperren());

        try {
            $ergebnis = $this->ermittlung()->korrigieren($anschrift, new Anschrift('Am Markt 3', '12345', 'Musterstadt'), $this->jetzt);
        } finally {
            $sperre->freigeben();
        }

        self::assertSame(HandSuche::Belegt, $ergebnis);
        self::assertSame([], $this->http->anfragen);
        self::assertNull($this->geo()->eintrag($anschrift->hash()), 'nothing saved');
    }

    public function testTheStatesOfTheBlock(): void
    {
        $offen = $this->mitglied(1, 'Hauptstraße 1');
        $ohne = $this->mitglied(2, '', '', '');
        $ehemalig = $this->mitglieder->speichern(
            new Mitglied(3, 'Alt', 'Anna', strasse: 'Weg 1', plz: '12345', ort: 'Musterstadt', austrittsdatum: new \DateTimeImmutable('2020-01-01')),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied;

        self::assertSame(WohnortZustand::Offen, Wohnort::aus($offen, $this->geo(), $this->jetzt)->zustand);
        self::assertSame(WohnortZustand::OhneAnschrift, Wohnort::aus($ohne, $this->geo(), $this->jetzt)->zustand);
        self::assertSame(WohnortZustand::Ehemalig, Wohnort::aus($ehemalig, $this->geo(), $this->jetzt)->zustand);
        self::assertSame('Musterstadt', Wohnort::aus($offen, $this->geo(), $this->jetzt)->vorbelegung()->ort, 'prefilled: town up to the minus');

        $this->geo()->speichern(Wohnort::anschrift($offen)->hash(), GeoStatus::NichtGefunden, null, null, $this->jetzt);
        self::assertSame(WohnortZustand::NichtGefunden, Wohnort::aus($offen, $this->geo(), $this->jetzt)->zustand);
    }
}
