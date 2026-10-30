<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config\Paths;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\GeoAnschriftRepository;
use App\Repository\MitgliedRepository;
use App\Service\Cron\CronAufgaben;
use App\Service\Cron\KachelAufraeumenTask;
use App\Service\Cron\KoordinatenTask;
use App\Service\Karte\Anschrift;
use App\Service\Karte\GeoStatus;
use App\Service\Karte\HttpAbruf;
use App\Service\Karte\HttpAntwort;
use App\Service\Karte\Takt;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeHttpAbruf;
use App\Tests\Support\FakeTakt;

/** The page "Karte" (issue #91) through the whole application, with fake external services. */
final class KarteSeiteTest extends AppTestCase
{
    private string $wurzel;

    private FakeHttpAbruf $http;

    private bool $dienstAus = false;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function paths(): ?Paths
    {
        $this->wurzel = sys_get_temp_dir() . '/mv-karte-' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/shared', 0775, true);
        symlink(dirname(__DIR__, 2), $this->wurzel . '/current');

        return new Paths($this->wurzel . '/current');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpAbruf(fn(string $url): HttpAntwort => match (true) {
            $this->dienstAus => new HttpAntwort(0),
            str_contains($url, 'tile.openstreetmap.org') => new HttpAntwort(200, FakeHttpAbruf::png(), 'image/png'),
            default => FakeHttpAbruf::treffer(48.0, 11.0),
        });
        $http = $this->http;
        $this->dienste->dienst(HttpAbruf::class, static fn(): HttpAbruf => $http);
        $this->dienste->dienst(Takt::class, static fn(): Takt => new FakeTakt());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (!isset($this->wurzel)) {
            return;
        }
        @unlink($this->wurzel . '/current');
        if (is_dir($this->wurzel . '/shared')) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->wurzel . '/shared', \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $eintrag) {
                $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
            }
            rmdir($this->wurzel . '/shared');
        }
        @rmdir($this->wurzel);
    }

    private function mitglied(int $nr, string $nachname, string $strasse, string $plz = '12345', string $ort = 'Musterstadt', bool $aktiv = true): int
    {
        return (int) new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied($nr, $nachname, 'Erika', strasse: $strasse, plz: $plz, ort: $ort, aktiv: $aktiv),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied->id;
    }

    private function koordinaten(string $strasse, GeoStatus $status, ?float $lat = null, ?float $lon = null): void
    {
        new GeoAnschriftRepository($this->dienste->pdo())->speichern(
            new Anschrift($strasse, '12345', 'Musterstadt')->hash(),
            $status,
            $lat,
            $lon,
            new \DateTimeImmutable(),
        );
    }

    public function testTheBoardSeesTheMapAndTheTextList(): void
    {
        $eins = $this->mitglied(101, 'Musterfrau', 'Hauptstraße 1');
        $zwei = $this->mitglied(102, 'Mustermann', 'Hauptstr. 1', aktiv: false);
        $drei = $this->mitglied(103, 'Beispiel', 'Gibtsnichtweg 9');
        $this->mitglied(104, 'Offen', 'Neuweg 2');
        $this->mitglied(105, 'Ohne', '', '', '');
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 48.137154, 11.576124);
        $this->koordinaten('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $this->als(Rolle::Vorstand);

        $antwort = $this->get('/app/karte');

        self::assertSame(200, $antwort->status);
        $html = $antwort->body;
        self::assertStringContainsString('<h1>Karte</h1>', $html);
        self::assertStringContainsString('1 von 3 Anschriften gefunden', $html);
        self::assertStringContainsString('id="karte"', $html);
        self::assertStringContainsString('data-kacheln="/app/karte/kachel/{z}/{x}/{y}.png"', $html);
        self::assertStringContainsString('/js/vendor/leaflet/leaflet.js', $html);
        self::assertStringContainsString('/js/vendor/leaflet/leaflet.css', $html);
        self::assertStringContainsString('/js/karte.js', $html);
        // One marker for the household of two.
        self::assertSame(1, substr_count($html, 'data-lat="48.137154" data-lon="11.576124"'));
        self::assertStringContainsString('Anschriften auf der Karte (1)', $html);
        self::assertStringContainsString('/app/mitglieder?mitglied=' . $eins, $html);
        self::assertStringContainsString('/app/mitglieder?mitglied=' . $zwei, $html);
        self::assertStringContainsString('Nicht gefundene Anschriften (1)', $html);
        self::assertStringContainsString('/app/mitglieder?mitglied=' . $drei, $html);
        self::assertStringContainsString('Noch nicht ermittelt (1)', $html);
        self::assertStringContainsString('Ohne Anschrift (1)', $html);
        self::assertStringContainsString('OpenStreetMap</a>-Mitwirkende', (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/karte.js'));
        // The button is the admin's.
        self::assertStringNotContainsString('Jetzt Koordinaten ermitteln', $html);
        self::assertSame([], $this->http->anfragen, 'showing the page asks no external service');
    }

    public function testTheFilterNarrowsTheList(): void
    {
        $this->mitglied(101, 'Aktivfrau', 'Hauptstraße 1');
        $this->mitglied(102, 'Passivmann', 'Bahnhofstraße 2', aktiv: false);
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 48.1, 11.5);
        $this->koordinaten('Bahnhofstraße 2', GeoStatus::Gefunden, 48.2, 11.6);
        $this->als(Rolle::Vorstand);

        $html = $this->get('/app/karte', ['status' => 'passiv'])->body;

        self::assertStringContainsString('Passivmann', $html);
        self::assertStringNotContainsString('Aktivfrau', $html);
        self::assertStringContainsString('Filter zurücksetzen', $html);
    }

    public function testTheAdminCanAskForTheCoordinatesNow(): void
    {
        $this->mitglied(101, 'Musterfrau', 'Hauptstraße 1');
        $this->mitglied(102, 'Mustermann', 'Bahnhofstraße 2');
        $this->als(Rolle::Admin);

        self::assertStringContainsString('Jetzt Koordinaten ermitteln', $this->get('/app/karte')->body);

        $antwort = $this->post('/app/karte/ermitteln');

        self::assertSame(200, $antwort->status);
        $stand = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $stand['gefunden']);
        self::assertSame(0, $stand['offen']);
        self::assertTrue($stand['fertig']);
        self::assertCount(2, $this->http->anfragen);
        self::assertStringStartsWith('Mitgliederverwaltung/0.0.0-test (+', $this->http->anfragen[0]['userAgent']);
    }

    public function testTheBoardCannotTriggerTheGeocoding(): void
    {
        $this->mitglied(101, 'Musterfrau', 'Hauptstraße 1');
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/app/karte/ermitteln')->status);
        self::assertSame([], $this->http->anfragen);
    }

    public function testATileComesThroughTheServerAndThenFromItsCache(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->get('/app/karte/kachel/13/4400/2850.png');

        self::assertSame(200, $antwort->status);
        self::assertSame('image/png', $antwort->headers['Content-Type']);
        self::assertSame(FakeHttpAbruf::png(), $antwort->body);
        self::assertSame(['https://tile.openstreetmap.org/13/4400/2850.png'], $this->http->urls());
        self::assertFileExists($this->wurzel . '/shared/var/kacheln/13/4400/2850.png');

        self::assertSame(200, $this->get('/app/karte/kachel/13/4400/2850.png')->status);
        self::assertCount(1, $this->http->anfragen, 'cache hit');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ungueltigeKacheln(): iterable
    {
        yield 'zoom too small' => ['/app/karte/kachel/2/1/1.png'];
        yield 'zoom too large' => ['/app/karte/kachel/19/1/1.png'];
        yield 'x outside' => ['/app/karte/kachel/5/32/1.png'];
        yield 'y outside' => ['/app/karte/kachel/5/1/32.png'];
        yield 'absurd number' => ['/app/karte/kachel/5/99999999999999999999/1.png'];
        yield 'three digit zoom' => ['/app/karte/kachel/105/1/1.png'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeKacheln')]
    public function testAnInvalidTileIsNotFoundAndNotFetched(string $pfad): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(404, $this->get($pfad)->status);
        self::assertSame([], $this->http->anfragen);
    }

    public function testWithoutLoginThereIsNoTileAndNoMap(): void
    {
        $kachel = $this->get('/app/karte/kachel/13/4400/2850.png');
        $seite = $this->get('/app/karte');

        self::assertNotSame(200, $kachel->status);
        self::assertNotSame(200, $seite->status);
        self::assertSame([], $this->http->anfragen);
    }

    public function testATileServerFailureIsAHintNotACrash(): void
    {
        $this->dienstAus = true;
        $this->als(Rolle::Vorstand);

        self::assertSame(503, $this->get('/app/karte/kachel/13/4400/2850.png')->status);
    }

    public function testTheCronFindsBothTasks(): void
    {
        $klassen = array_map(static fn(object $t): string => $t::class, CronAufgaben::alle($this->dienste));

        self::assertContains(KoordinatenTask::class, $klassen);
        self::assertContains(KachelAufraeumenTask::class, $klassen);
    }

    public function testTheCronTaskLooksUpAFewAddresses(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->mitglied(100 + $i, 'Muster' . $i, 'Weg ' . $i);
        }

        $erledigt = KoordinatenTask::erzeugen($this->dienste)->run(new \DateTimeImmutable());

        self::assertSame(KoordinatenTask::JE_LAUF, $erledigt);
        self::assertCount(KoordinatenTask::JE_LAUF, $this->http->anfragen);
    }
}
