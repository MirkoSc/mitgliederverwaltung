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
use App\Service\Karte\HttpAntwort;
use App\Service\Karte\Koordinatenermittlung;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeHttpAbruf;
use App\Tests\Support\FakeTakt;

/**
 * The geocoding of issue #91 against the real database, with a fake
 * geocoder and a fake clock: rate limit, cache per address, "not found"
 * remembered, changed addresses, failures of the service.
 */
final class KoordinatenermittlungTest extends DatabaseTestCase
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
        $this->antwort = static fn(string $url): HttpAntwort => FakeHttpAbruf::treffer(48.0, 11.0);
        $takt = $this->takt;
        // Every request takes a quarter of a second on the fake clock.
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

    private function mitglied(int $nr, string $strasse, string $plz = '12345', string $ort = 'Musterstadt', ?string $austritt = null): Mitglied
    {
        return $this->mitglieder->speichern(
            new Mitglied($nr, 'Muster' . $nr, 'Erika', strasse: $strasse, plz: $plz, ort: $ort, austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt)),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied;
    }

    public function testEachAddressIsAskedOnceAndAHouseholdSharesIt(): void
    {
        $this->mitglied(1, 'Hauptstraße 1');
        $this->mitglied(2, 'Hauptstr. 1');          // same household, other spelling
        $this->mitglied(3, 'Bahnhofstraße 7');

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);

        self::assertCount(2, $this->http->anfragen);
        self::assertSame(2, $stand->bearbeitet);
        self::assertSame(2, $stand->gesamt);
        self::assertSame(2, $stand->gefunden);
        self::assertSame(0, $stand->offen());
        self::assertTrue($stand->fertig());
        self::assertSame('2 von 2 Anschriften gefunden', $stand->text());

        // Cache: a second run asks nobody.
        $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);
        self::assertCount(2, $this->http->anfragen);

        $zeilen = new GeoAnschriftRepository($this->pdo())->alle();
        $eintrag = $zeilen[new Anschrift('Hauptstraße 1', '12345', 'Musterstadt')->hash()];
        self::assertSame(GeoStatus::Gefunden, $eintrag['status']);
        self::assertEqualsWithDelta(48.0, $eintrag['lat'], 0.000001);
    }

    public function testNoNameAndNoMemberNumberLeavesTheServer(): void
    {
        $this->mitglied(4711, 'Hauptstraße 1');

        $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);

        $url = $this->http->urls()[0];
        self::assertStringNotContainsString('Muster4711', $url);
        self::assertStringNotContainsString('Erika', $url);
        self::assertStringNotContainsString('4711', $url);
    }

    public function testAtMostOneRequestPerSecondAcrossCalls(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->mitglied($i, 'Weg ' . $i);
        }

        $zeiten = [];
        $takt = $this->takt;
        $this->antwort = static function () use ($takt, &$zeiten): HttpAntwort {
            $zeiten[] = $takt->jetzt();

            return FakeHttpAbruf::treffer(48.0, 11.0);
        };

        $this->ermittlung()->ermitteln($this->jetzt, 2, 12.0);
        // The next call (the cron right after the button) keeps the distance too.
        $this->ermittlung()->ermitteln($this->jetzt, 2, 12.0);

        self::assertCount(4, $zeiten);
        for ($i = 1; $i < count($zeiten); $i++) {
            // Start of this request (its answer came 0.25 s later) minus the
            // end of the previous one.
            $abstand = ($zeiten[$i] - 0.25) - $zeiten[$i - 1];
            self::assertGreaterThanOrEqual(Koordinatenermittlung::ABSTAND_S - 0.0001, $abstand, 'request ' . $i);
        }
        self::assertNotEmpty($this->takt->pausen);
    }

    public function testFewAddressesPerCallAndATimeBudget(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->mitglied($i, 'Weg ' . $i);
        }

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 5, 60.0);
        self::assertSame(5, $stand->bearbeitet);
        self::assertSame(7, $stand->offen());
        self::assertFalse($stand->fertig());

        // ~1.35 s per address on the fake clock: a budget of 3 s stops early.
        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 3.0);
        self::assertLessThanOrEqual(3, $stand->bearbeitet);
        self::assertGreaterThanOrEqual(1, $stand->bearbeitet);
    }

    public function testNotFoundIsRememberedAndNotAskedAgain(): void
    {
        $this->mitglied(1, 'Gibtsnichtweg 99');
        $this->antwort = static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer();

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);
        self::assertSame(1, $stand->nichtGefunden);
        self::assertSame(0, $stand->gefunden);
        self::assertSame(0, $stand->offen());
        self::assertSame('0 von 1 Anschrift gefunden', $stand->text());

        $this->ermittlung()->ermitteln($this->jetzt->modify('+30 days'), 10, 12.0);
        self::assertCount(1, $this->http->anfragen);
    }

    public function testAChangedAddressIsLookedUpAgainAndTheOldOneLeaves(): void
    {
        $mitglied = $this->mitglied(1, 'Hauptstraße 1');
        $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);
        $alt = new Anschrift('Hauptstraße 1', '12345', 'Musterstadt')->hash();

        $this->mitglieder->speichern($mitglied->mit(['strasse' => 'Neue Straße 5']), VersionsAnlass::geaendert(), 1);
        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);

        self::assertCount(2, $this->http->anfragen);
        self::assertStringContainsString('Neue%20Stra%C3%9Fe%205', $this->http->urls()[1]);
        self::assertSame(1, $stand->gefunden);
        $zeilen = new GeoAnschriftRepository($this->pdo())->alle();
        self::assertArrayNotHasKey($alt, $zeilen, 'nobody lives at the old address any more');
        self::assertArrayHasKey(new Anschrift('Neue Straße 5', '12345', 'Musterstadt')->hash(), $zeilen);
    }

    public function testFormerMembersAndEmptyAddressesAreNotAsked(): void
    {
        $this->mitglied(1, 'Altweg 1', austritt: '2025-12-31');
        $this->mitglied(2, 'Irgendwo 3', '', '');

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);

        self::assertSame([], $this->http->anfragen);
        self::assertSame(0, $stand->gesamt);
        self::assertTrue($stand->fertig());
    }

    public function testAFailureStoresNothingAndPausesTheGeocoding(): void
    {
        $this->mitglied(1, 'Hauptstraße 1');
        $this->mitglied(2, 'Bahnhofstraße 7');
        $this->antwort = static fn(): HttpAntwort => new HttpAntwort(429);

        $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);

        self::assertCount(1, $this->http->anfragen, 'the run stops at the first failure');
        self::assertSame(0, $stand->bearbeitet);
        self::assertSame(2, $stand->offen());
        self::assertSame('HTTP 429', $stand->fehler);
        self::assertNotNull($stand->pauseBis);
        self::assertTrue($stand->fertig(), 'the chain stops while the service rests');
        self::assertSame([], new GeoAnschriftRepository($this->pdo())->alle());

        // During the pause nobody is asked ...
        $this->antwort = static fn(): HttpAntwort => FakeHttpAbruf::treffer(48.0, 11.0);
        $this->ermittlung()->ermitteln($this->jetzt->modify('+10 minutes'), 10, 12.0);
        self::assertCount(1, $this->http->anfragen);

        // ... afterwards it goes on.
        $stand = $this->ermittlung()->ermitteln($this->jetzt->modify(sprintf('+%d minutes', Koordinatenermittlung::PAUSE_MINUTEN + 1)), 10, 12.0);
        self::assertSame(2, $stand->gefunden);
        self::assertNull($stand->pauseBis);
    }

    public function testWhileAnotherRequestHoldsTheLockNothingIsAsked(): void
    {
        $this->mitglied(1, 'Hauptstraße 1');
        // A second connection - the cron in another request: GET_LOCK
        // belongs to a connection.
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
            $stand = $this->ermittlung()->ermitteln($this->jetzt, 10, 12.0);
        } finally {
            $sperre->freigeben();
        }

        self::assertTrue($stand->laeuftBereits);
        self::assertFalse($stand->fertig());
        self::assertSame([], $this->http->anfragen);
    }
}
