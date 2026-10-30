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
            default => FakeHttpAbruf::trefferFuer($url, 48.0, 11.0),
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
        for ($i = 1; $i <= KoordinatenTask::JE_LAUF + 5; $i++) {
            $this->mitglied(100 + $i, 'Muster' . $i, 'Weg ' . $i);
        }

        $erledigt = KoordinatenTask::erzeugen($this->dienste)->run(new \DateTimeImmutable());

        self::assertSame(KoordinatenTask::JE_LAUF, $erledigt);
        self::assertCount(KoordinatenTask::JE_LAUF, $this->http->anfragen);
    }

    /** A POST of the script: with the CSRF token and the background header (JSON answer). */
    private function postImHintergrund(string $pfad, array $felder = []): \App\Http\Response
    {
        $session = new \App\Http\Session();
        $session->start();
        $felder['_csrf'] = $session->csrfToken();

        return $this->aufrufen(new \App\Http\Request(\App\Http\HttpMethod::Post, $pfad, post: $felder, headers: ['x-hintergrund' => '1']));
    }

    public function testApproximateAndHandSetDotsAreLabelledAndListed(): void
    {
        $ungefaehr = $this->mitglied(101, 'Musterfrau', 'Am Hang 12');
        $this->mitglied(102, 'Mustermann', 'Hauptstraße 1');
        $this->mitglied(103, 'Beispiel', 'Bahnhofstraße 3');
        $this->koordinaten('Am Hang 12', GeoStatus::Ungefaehr, 50.0329, 9.1424);
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 50.03, 9.14);
        new GeoAnschriftRepository($this->dienste->pdo())->punktSetzen(new Anschrift('Bahnhofstraße 3', '12345', 'Musterstadt')->hash(), 50.04, 9.15, new \DateTimeImmutable());
        $this->als(Rolle::Vorstand);

        $html = $this->get('/app/karte')->body;

        self::assertStringContainsString('1 von 3 Anschriften gefunden, 1 ungefähr, 1 von Hand gesetzt', $html);
        self::assertStringContainsString('Anschriften auf der Karte (3)', $html, 'all three have a dot');
        self::assertStringContainsString('<tr data-genauigkeit="ungefaehr" data-lat="50.032900"', $html);
        self::assertStringContainsString('<tr data-genauigkeit="von_hand" data-lat="50.040000"', $html);
        self::assertStringContainsString('<span class="etikett etikett-warnung" data-karte-etikett>ungefähr</span>', $html);
        self::assertStringContainsString('<span class="etikett etikett-info" data-karte-etikett>von Hand</span>', $html);
        self::assertStringContainsString('Ungefähr gefundene Anschriften (1)', $html);
        self::assertStringContainsString('href="/app/mitglieder?mitglied=' . $ungefaehr . '&amp;reiter=daten#wohnort"', $html);
        self::assertStringNotContainsString('Alle neu ermitteln', $html, 'the board does not get the button');
    }

    public function testTheAdminSeesTheHintOnSpeedAndTheQuestionBeforeRerunningAll(): void
    {
        $this->mitglied(101, 'Musterfrau', 'Hauptstraße 1');
        $this->als(Rolle::Admin);

        $html = $this->get('/app/karte')->body;

        self::assertStringContainsString('Am schnellsten: Knopf drücken und den Tab offen lassen (etwa eine Anschrift pro Sekunde).', $html);
        self::assertStringContainsString('<summary>Alle neu ermitteln</summary>', $html);
        self::assertStringContainsString('etwa eine Sekunde je Anschrift', $html);
        self::assertStringContainsString('<input type="checkbox" name="sicher" value="1" required>', $html);
        self::assertStringContainsString('data-kette="/app/karte/ermitteln"', $html);
    }

    public function testRerunningAllForgetsTheAutomaticResultsAndKeepsWhatWasDoneByHand(): void
    {
        foreach (['Hauptstraße 1', 'Am Hang 12', 'Gibtsnichtweg 9', 'Bahnhofstraße 3', 'Kirchweg 5'] as $nr => $strasse) {
            $this->mitglied(101 + $nr, 'Muster' . $nr, $strasse);
        }
        $geo = new GeoAnschriftRepository($this->dienste->pdo());
        $hash = static fn(string $strasse): string => new Anschrift($strasse, '12345', 'Musterstadt')->hash();
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 50.0, 9.0);
        $this->koordinaten('Am Hang 12', GeoStatus::Ungefaehr, 50.1, 9.1);
        $this->koordinaten('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $geo->punktSetzen($hash('Bahnhofstraße 3'), 50.2, 9.2, new \DateTimeImmutable());
        $geo->korrekturSetzen($hash('Kirchweg 5'), 'Kirchplatz 5', '12345', 'Musterstadt', new \DateTimeImmutable());
        $geo->speichern($hash('Kirchweg 5'), GeoStatus::Gefunden, 50.3, 9.3, new \DateTimeImmutable());
        $this->als(Rolle::Admin);

        // Without the tick nothing happens.
        $antwort = $this->post('/app/karte/neu-ermitteln');
        self::assertWeiterleitungNach('/app/karte', $antwort);
        self::assertSame(GeoStatus::Gefunden, $geo->eintrag($hash('Hauptstraße 1'))['status'] ?? null);
        self::assertStringContainsString('Bitte bestätigen Sie die Rückfrage', $this->get('/app/karte')->body);

        $antwort = $this->postImHintergrund('/app/karte/neu-ermitteln', ['sicher' => '1']);

        self::assertSame(200, $antwort->status);
        $stand = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $stand['von_hand']);
        self::assertSame(4, $stand['offen']);
        self::assertFalse($stand['fertig'], 'the script goes on with the chain');
        self::assertSame([], $this->http->anfragen, 'forgetting asks nobody');
        self::assertNull($geo->eintrag($hash('Hauptstraße 1')));
        self::assertNull($geo->eintrag($hash('Am Hang 12')));
        self::assertNull($geo->eintrag($hash('Gibtsnichtweg 9')));
        self::assertSame(GeoStatus::VonHand, $geo->eintrag($hash('Bahnhofstraße 3'))['status'] ?? null);
        $kirche = $geo->eintrag($hash('Kirchweg 5'));
        self::assertNotNull($kirche, 'the row with the search address stays');
        self::assertNull($kirche['status']);
        self::assertSame('Kirchplatz 5', $kirche['korrektur']['strasse'] ?? null, 'the search address stays');

        $audit = $this->dienste->pdo()->query("SELECT details FROM audit_log WHERE action = 'karte.neu_ermittelt'")->fetchColumn();
        self::assertSame(['vergessen' => 4], json_decode((string) $audit, true));

        // The chain then asks again - the search address with itself, the point by hand not at all.
        $this->post('/app/karte/ermitteln');
        self::assertCount(4, $this->http->anfragen);
        self::assertSame(1, substr_count(implode(' ', $this->http->urls()), 'Kirchplatz'));
        self::assertStringNotContainsString('Bahnhof', implode(' ', $this->http->urls()));
        self::assertSame(GeoStatus::VonHand, $geo->eintrag($hash('Bahnhofstraße 3'))['status'] ?? null);
    }

    public function testRerunningAllWithoutScriptComesBackToThePage(): void
    {
        $this->mitglied(101, 'Musterfrau', 'Hauptstraße 1');
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 50.0, 9.0);
        $this->als(Rolle::Admin);

        self::assertWeiterleitungNach('/app/karte', $this->post('/app/karte/neu-ermitteln', ['sicher' => '1']));
        self::assertStringContainsString('Die bisherigen Ergebnisse sind vergessen.', $this->get('/app/karte')->body);
        self::assertSame([], new GeoAnschriftRepository($this->dienste->pdo())->alle());
    }

    public function testTheBoardCannotRerunAll(): void
    {
        $this->mitglied(101, 'Musterfrau', 'Hauptstraße 1');
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 50.0, 9.0);
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/app/karte/neu-ermitteln', ['sicher' => '1'])->status);
        self::assertCount(1, new GeoAnschriftRepository($this->dienste->pdo())->alle());
    }

    public function testTheMapHintsAreInTheListAuffaelligkeitenAndOpenTheData(): void
    {
        $ungefaehr = $this->mitglied(101, 'Musterfrau', 'Am Hang 12');
        $nicht = $this->mitglied(102, 'Mustermann', 'Gibtsnichtweg 9');
        $this->mitglied(103, 'Beispiel', 'Hauptstraße 1');
        $this->koordinaten('Am Hang 12', GeoStatus::Ungefaehr, 50.0329, 9.1424);
        $this->koordinaten('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $this->koordinaten('Hauptstraße 1', GeoStatus::Gefunden, 50.03, 9.14);
        $this->als(Rolle::Vorstand);

        $html = $this->get('/app/listen/auffaelligkeiten', ['art' => 'karte-ungefaehr'])->body;
        self::assertStringContainsString('Anschrift auf der Karte nur ungefähr gefunden', $html);
        self::assertStringContainsString('mitglied=' . $ungefaehr . '&amp;reiter=daten', $html);
        self::assertStringNotContainsString('mitglied=' . $nicht . '&amp;', $html);

        $html = $this->get('/app/listen/auffaelligkeiten', ['art' => 'karte-nicht-gefunden'])->body;
        self::assertStringContainsString('Die Anschrift wurde auf der Karte nicht gefunden.', $html);
        self::assertStringContainsString('mitglied=' . $nicht . '&amp;reiter=daten', $html);
        self::assertStringNotContainsString('mitglied=' . $ungefaehr . '&amp;', $html);
        self::assertStringNotContainsString('Beispiel', $html);
        self::assertSame([], $this->http->anfragen);
    }
}
