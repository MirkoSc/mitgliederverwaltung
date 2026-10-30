<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config\Paths;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\GeoAnschriftRepository;
use App\Repository\MitgliedRepository;
use App\Service\Karte\Anschrift;
use App\Service\Karte\GeoStatus;
use App\Service\Karte\HttpAbruf;
use App\Service\Karte\HttpAntwort;
use App\Service\Karte\Takt;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeHttpAbruf;
use App\Tests\Support\FakeTakt;

/**
 * The block "Wohnort auf der Karte" in the tab "Daten" (issue #106)
 * through the whole application, with a fake geocoder: the three states,
 * the search address, rights, audit and the way back to the embedding page.
 */
final class WohnortTest extends AppTestCase
{
    private string $wurzel;

    private FakeHttpAbruf $http;

    /** @var \Closure(string): HttpAntwort */
    private \Closure $antwort;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function paths(): ?Paths
    {
        $this->wurzel = sys_get_temp_dir() . '/mv-wohnort-' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/shared', 0775, true);
        symlink(dirname(__DIR__, 2), $this->wurzel . '/current');

        return new Paths($this->wurzel . '/current');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->antwort = static fn(string $url): HttpAntwort => FakeHttpAbruf::trefferFuer($url, 48.137154, 11.576124);
        $this->http = new FakeHttpAbruf(fn(string $url): HttpAntwort => ($this->antwort)($url));
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
        @rmdir($this->wurzel . '/shared');
        @rmdir($this->wurzel);
    }

    private function mitglied(int $nr, string $strasse, string $ort = 'Musterstadt - OAB'): int
    {
        return (int) new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied($nr, 'Musterfrau', 'Erika', strasse: $strasse, plz: '12345', ort: $ort),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied->id;
    }

    private function geoStatus(string $strasse, GeoStatus $status, ?float $lat = null, ?float $lon = null): void
    {
        new GeoAnschriftRepository($this->dienste->pdo())->speichern(
            new Anschrift($strasse, '12345', 'Musterstadt - OAB')->hash(),
            $status,
            $lat,
            $lon,
            new \DateTimeImmutable(),
        );
    }

    private function daten(int $id): string
    {
        $antwort = $this->get('/app/mitglieder', ['mitglied' => (string) $id, 'reiter' => 'daten']);
        self::assertSame(200, $antwort->status);

        return $antwort->body;
    }

    public function testFoundShowsTheSmallMapAndTheLinkToTheBigOne(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->geoStatus('Hauptstraße 1', GeoStatus::Gefunden, 48.137154, 11.576124);
        $this->als(Rolle::Vorstand);

        $html = $this->daten($id);

        self::assertStringContainsString('Wohnort auf der Karte', $html);
        self::assertStringContainsString('Die Anschrift steht auf der Karte.', $html);
        self::assertStringContainsString('data-wohnort-karte', $html);
        self::assertStringContainsString('data-lat="48.137154" data-lon="11.576124"', $html);
        self::assertStringContainsString('data-kacheln="/app/karte/kachel/{z}/{x}/{y}.png"', $html);
        self::assertStringContainsString('aria-label="Kleine Karte mit dem Wohnort Hauptstraße 1, 12345 Musterstadt - OAB"', $html);
        self::assertStringContainsString('href="/app/karte?mitglied=' . $id . '">Auf der großen Karte zeigen</a>', $html);
        self::assertStringContainsString('Lage stimmt nicht? Anders suchen', $html);
        // Leaflet and the map script come along; nothing from a foreign host.
        self::assertStringContainsString('/js/vendor/leaflet/leaflet.js', $html);
        self::assertStringContainsString('/js/vendor/leaflet/leaflet.css', $html);
        self::assertStringContainsString('/js/karte.js', $html);
        self::assertStringNotContainsString('openstreetmap.org', $html);
        // The correction form stands outside the member form; its fields point to it.
        self::assertStringContainsString('<form id="wohnort-formular" method="post" action="/app/mitglieder/' . $id . '/wohnort">', $html);
        self::assertStringContainsString('name="such_ort" value="Musterstadt" maxlength="100" autocomplete="off" form="wohnort-formular"', $html);
        self::assertSame([], $this->http->anfragen, 'showing a member asks no external service');
    }

    public function testNotFoundShowsTheFormWithTheAddressPrefilled(): void
    {
        $id = $this->mitglied(101, 'Gibtsnichtweg 9');
        $this->geoStatus('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $this->als(Rolle::Vorstand);

        $html = $this->daten($id);

        self::assertStringContainsString('Die Anschrift wurde auf der Karte nicht gefunden.', $html);
        // The small map without a dot, to set one by hand (issue #108): no
        // other dot in the club, so it opens on Germany.
        self::assertStringContainsString('data-wohnort-karte', $html);
        self::assertStringNotContainsString('data-lat=', $html);
        self::assertStringContainsString('data-mitte-lat="51.163400" data-mitte-lon="10.447700" data-mitte-zoom="6"', $html);
        self::assertStringContainsString('data-setzen="#wohnort-punkt"', $html);
        self::assertStringContainsString('<details class="wohnort-korrektur" open>', $html);
        self::assertStringContainsString('name="such_strasse" value="Gibtsnichtweg 9"', $html);
        self::assertStringContainsString('name="such_plz" value="12345"', $html);
        self::assertStringContainsString('name="such_ort" value="Musterstadt"', $html, 'town up to the first minus');
        self::assertStringContainsString('value="korrigieren" form="wohnort-formular">Mit dieser Anschrift suchen</button>', $html);
    }

    public function testNotAskedYetOffersJetztSuchen(): void
    {
        $id = $this->mitglied(101, 'Neuweg 2');
        $this->als(Rolle::Vorstand);

        $html = $this->daten($id);

        self::assertStringContainsString('noch nicht gesucht', $html);
        self::assertStringContainsString('Wird noch gesucht.', $html);
        self::assertStringContainsString('value="suchen" form="wohnort-formular">Jetzt suchen</button>', $html);

        $antwort = $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'suchen']);

        self::assertWeiterleitungNach('/app/mitglieder?mitglied=' . $id . '&reiter=daten#wohnort', $antwort);
        self::assertCount(1, $this->http->anfragen);
        $html = $this->daten($id);
        self::assertStringContainsString('Die Anschrift wurde auf der Karte gefunden.', $html, 'flash message');
        self::assertStringContainsString('data-wohnort-karte', $html);
    }

    public function testACorrectionIsSavedAskedAndAudited(): void
    {
        $id = $this->mitglied(101, 'Gibtsnichtweg 9');
        $this->geoStatus('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $this->antwort = static fn(string $url): HttpAntwort => str_contains($url, 'Am%20Markt')
            ? FakeHttpAbruf::trefferFuer($url, 49.5, 9.25)
            : FakeHttpAbruf::keinTreffer();
        $this->als(Rolle::Vorstand);
        $zurueck = '/app/listen/geburtstage?zeitraum=jahr';

        $antwort = $this->post("/app/mitglieder/$id/wohnort", [
            'aktion' => 'korrigieren',
            'such_strasse' => 'Am Markt 3',
            'such_plz' => '12345',
            'such_ort' => 'Musterstadt',
            'zurueck' => $zurueck,
        ]);

        self::assertWeiterleitungNach($zurueck . '&mitglied=' . $id . '&reiter=daten#wohnort', $antwort);
        self::assertStringContainsString('street=Am%20Markt%203', $this->http->urls()[0]);
        $html = $this->daten($id);
        self::assertStringContainsString('Die Such-Anschrift ist gespeichert. Die Anschrift wurde auf der Karte gefunden.', $html);
        self::assertStringContainsString('data-lat="49.500000" data-lon="9.250000"', $html);
        self::assertStringContainsString('Gesucht wird mit der Such-Anschrift „Am Markt 3, 12345 Musterstadt“', $html);
        self::assertStringContainsString('>Korrektur entfernen</button>', $html);
        self::assertSame('Gibtsnichtweg 9', new MitgliedRepository($this->dienste->pdo())->finde($id)?->strasse, 'the member keeps its address');

        $zeile = $this->dienste->pdo()->query("SELECT entity, entity_id, details FROM audit_log WHERE action = 'mitglied.karten_suche_geaendert'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($zeile);
        self::assertSame(['mitglied', (string) $id], [$zeile['entity'], (string) $zeile['entity_id']]);
        self::assertSame(['korrektur' => 'gesetzt', 'ergebnis' => 'gefunden'], json_decode((string) $zeile['details'], true));
        foreach (['Markt', 'Gibtsnicht', '12345', 'Musterstadt'] as $teil) {
            self::assertStringNotContainsString($teil, (string) $zeile['details'], 'no address in the audit log');
        }
        $log = (string) @file_get_contents(sys_get_temp_dir() . '/mv-test-' . getmypid() . '/app.log');
        self::assertStringNotContainsString('Markt', $log, 'no address in the log');
    }

    public function testACorrectionStillNotFoundKeepsTheTypedValues(): void
    {
        $id = $this->mitglied(101, 'Gibtsnichtweg 9');
        $this->geoStatus('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $this->antwort = static fn(): HttpAntwort => FakeHttpAbruf::keinTreffer();
        $this->als(Rolle::Vorstand);

        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'korrigieren', 'such_strasse' => 'Gibtsauchnichtweg 1', 'such_plz' => '54321', 'such_ort' => 'Nirgendwo']);

        $html = $this->daten($id);
        self::assertStringContainsString('Auch mit ihr wurde die Anschrift auf der Karte nicht gefunden.', $html);
        self::assertStringContainsString('Die Anschrift wurde auf der Karte nicht gefunden.', $html);
        self::assertStringContainsString('name="such_strasse" value="Gibtsauchnichtweg 1"', $html);
        self::assertStringContainsString('name="such_plz" value="54321"', $html);
        self::assertStringContainsString('name="such_ort" value="Nirgendwo"', $html);
    }

    public function testRemovingTheCorrectionSearchesTheAddressAgain(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->als(Rolle::Admin);
        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'korrigieren', 'such_strasse' => 'Am Markt 3', 'such_plz' => '12345', 'such_ort' => 'Musterstadt']);

        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'entfernen']);

        self::assertCount(2, $this->http->anfragen);
        self::assertStringContainsString('street=Haupt', $this->http->urls()[1]);
        $html = $this->daten($id);
        self::assertStringContainsString('Die Such-Anschrift ist entfernt. Die Anschrift wurde auf der Karte gefunden.', $html);
        self::assertStringNotContainsString('Gesucht wird mit der Such-Anschrift', $html);
        self::assertSame(2, (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mitglied.karten_suche_geaendert'")->fetchColumn());
    }

    public function testAnEmptySearchAddressIsRefused(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->als(Rolle::Vorstand);

        $antwort = $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'korrigieren', 'such_strasse' => 'Am Markt 3', 'such_plz' => ' ', 'such_ort' => '']);

        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->http->anfragen);
        self::assertStringContainsString('mindestens PLZ oder Ort', $this->daten($id));
    }

    public function testReadOnlySeesTheStateButNoFormAndMayNotPost(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->geoStatus('Hauptstraße 1', GeoStatus::Gefunden, 48.137154, 11.576124);
        $this->als(Rolle::Lesend);

        $html = $this->daten($id);

        self::assertStringContainsString('Wohnort auf der Karte', $html);
        self::assertStringContainsString('data-wohnort-karte', $html, 'the map is there');
        self::assertStringContainsString('Auf der großen Karte zeigen', $html);
        self::assertStringNotContainsString('wohnort-formular', $html);
        self::assertStringNotContainsString('Anders suchen', $html);
        self::assertStringNotContainsString('Jetzt suchen', $html);

        $antwort = $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'korrigieren', 'such_strasse' => 'Am Markt 3', 'such_plz' => '12345', 'such_ort' => 'Musterstadt']);
        self::assertSame(403, $antwort->status);
        self::assertSame([], $this->http->anfragen);
        self::assertNull(new GeoAnschriftRepository($this->dienste->pdo())->eintrag(new Anschrift('Hauptstraße 1', '12345', 'Musterstadt - OAB')->hash())['korrektur'] ?? null);
    }

    public function testWithoutTheTokenNothingHappens(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->als(Rolle::Vorstand);

        $antwort = $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'suchen'], mitToken: false);

        self::assertSame(403, $antwort->status);
        self::assertSame([], $this->http->anfragen);
    }

    public function testTheMapPageLeadsToTheBlockAndCentresTheMember(): void
    {
        $gefunden = $this->mitglied(101, 'Hauptstraße 1');
        $nicht = $this->mitglied(102, 'Gibtsnichtweg 9');
        $this->geoStatus('Hauptstraße 1', GeoStatus::Gefunden, 48.137154, 11.576124);
        $this->geoStatus('Gibtsnichtweg 9', GeoStatus::NichtGefunden);
        $this->als(Rolle::Vorstand);

        $html = $this->get('/app/karte', ['mitglied' => (string) $gefunden])->body;

        self::assertStringContainsString('<tr data-fokus data-lat="48.137154"', $html);
        self::assertStringContainsString('href="/app/mitglieder?mitglied=' . $nicht . '&amp;reiter=daten#wohnort"', $html);
        self::assertStringNotContainsString('<tr data-fokus', $this->get('/app/karte')->body);
    }

    public function testFormerMembersAndEmptyAddressesSayWhy(): void
    {
        $ohne = (int) new MitgliedRepository($this->dienste->pdo())->speichern(new Mitglied(201, 'Ohne', 'Otto'), VersionsAnlass::angelegt(), 1)->mitglied->id;
        $this->als(Rolle::Vorstand);

        $html = $this->daten($ohne);
        self::assertStringContainsString('Ohne PLZ und Ort steht das Mitglied nicht auf der Karte.', $html);
        self::assertStringNotContainsString('Jetzt suchen', $html);

        $antwort = $this->post("/app/mitglieder/$ohne/wohnort", ['aktion' => 'suchen']);
        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->http->anfragen);
    }

    private function eintrag(string $strasse): ?array
    {
        return new GeoAnschriftRepository($this->dienste->pdo())->eintrag(new Anschrift($strasse, '12345', 'Musterstadt - OAB')->hash());
    }

    public function testAPointSetByHandIsSavedShownAndAuditedWithoutCoordinates(): void
    {
        $id = $this->mitglied(101, 'Am Hang 12');
        $this->geoStatus('Am Hang 12', GeoStatus::NichtGefunden);
        $this->als(Rolle::Vorstand);

        $antwort = $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-setzen', 'breite' => '50,0329', 'laenge' => '9.1424']);

        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->http->anfragen, 'setting a point asks nobody');
        $eintrag = $this->eintrag('Am Hang 12');
        self::assertSame(GeoStatus::VonHand, $eintrag['status'] ?? null);
        self::assertEqualsWithDelta(50.0329, $eintrag['lat'] ?? null, 0.000001);
        self::assertEqualsWithDelta(9.1424, $eintrag['lon'] ?? null, 0.000001);

        $html = $this->daten($id);
        self::assertStringContainsString('Der Punkt ist gespeichert.', $html);
        self::assertStringContainsString('<span class="etikett etikett-info">von Hand</span>', $html);
        self::assertStringContainsString('data-lat="50.032900" data-lon="9.142400"', $html);
        self::assertStringContainsString('data-genauigkeit="von_hand"', $html);
        self::assertStringContainsString('name="breite" value="50.032900"', $html);
        self::assertStringContainsString('>Punkt von Hand entfernen</button>', $html);
        self::assertStringNotContainsString('Anders suchen', $html, 'no search while a point is set by hand');
        self::assertStringNotContainsString('Mit einer anderen Anschrift suchen', $html);

        $zeile = $this->dienste->pdo()->query("SELECT entity, entity_id, details FROM audit_log WHERE action = 'mitglied.karten_punkt_geaendert'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($zeile);
        self::assertSame(['mitglied', (string) $id], [$zeile['entity'], (string) $zeile['entity_id']]);
        self::assertSame(['punkt' => 'gesetzt'], json_decode((string) $zeile['details'], true));
        foreach (['50', '9.14', 'Hang', '12345', 'Musterstadt'] as $teil) {
            self::assertStringNotContainsString($teil, (string) $zeile['details'], 'no address or coordinates in the audit log');
        }
    }

    public function testThePointCanBeMovedAndRemoved(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->als(Rolle::Admin);
        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-setzen', 'breite' => '48.1', 'laenge' => '11.5']);

        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-setzen', 'breite' => '48.2', 'laenge' => '11.6']);
        self::assertEqualsWithDelta(48.2, $this->eintrag('Hauptstraße 1')['lat'] ?? null, 0.000001);

        // A search is refused while the point stands - nothing changes.
        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'suchen']);
        self::assertSame([], $this->http->anfragen);
        self::assertStringContainsString('Entfernen Sie zuerst den Punkt von Hand', $this->daten($id));
        self::assertSame(GeoStatus::VonHand, $this->eintrag('Hauptstraße 1')['status'] ?? null);

        $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-entfernen']);

        self::assertCount(1, $this->http->anfragen, 'removing goes back to the automatic search');
        self::assertSame(GeoStatus::Gefunden, $this->eintrag('Hauptstraße 1')['status'] ?? null);
        self::assertStringContainsString('Der Punkt von Hand ist entfernt. Die Anschrift wurde auf der Karte gefunden.', $this->daten($id));
        self::assertSame(3, (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mitglied.karten_punkt_geaendert'")->fetchColumn());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ungueltigePunkte(): iterable
    {
        yield 'latitude too big' => ['90.5', '9'];
        yield 'longitude too small' => ['50', '-180.01'];
        yield 'empty' => ['', ''];
        yield 'text' => ['fünfzig', '9'];
        yield 'two commas' => ['50,1,2', '9'];
        yield 'exponent' => ['5e1', '9'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigePunkte')]
    public function testInvalidCoordinatesAreRefused(string $breite, string $laenge): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->als(Rolle::Vorstand);

        $antwort = $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-setzen', 'breite' => $breite, 'laenge' => $laenge]);

        self::assertSame(302, $antwort->status);
        self::assertNull($this->eintrag('Hauptstraße 1'));
        self::assertStringContainsString('Bitte geben Sie Breite (−90 bis 90) und Länge (−180 bis 180) als Zahlen an', $this->daten($id));
        self::assertSame(0, (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mitglied.karten_punkt_geaendert'")->fetchColumn());
    }

    public function testReadOnlyMayNotSetOrRemoveAPoint(): void
    {
        $id = $this->mitglied(101, 'Hauptstraße 1');
        $this->geoStatus('Hauptstraße 1', GeoStatus::Ungefaehr, 48.1, 11.5);
        $this->als(Rolle::Lesend);

        $html = $this->daten($id);
        self::assertStringContainsString('<span class="etikett etikett-warnung">ungefähr</span>', $html);
        self::assertStringNotContainsString('<summary>Punkt von Hand setzen</summary>', $html);
        self::assertStringNotContainsString('name="breite"', $html);
        self::assertStringNotContainsString('data-setzen', $html);

        self::assertSame(403, $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-setzen', 'breite' => '48.2', 'laenge' => '11.6'])->status);
        self::assertSame(403, $this->post("/app/mitglieder/$id/wohnort", ['aktion' => 'punkt-entfernen'])->status);
        self::assertSame(GeoStatus::Ungefaehr, $this->eintrag('Hauptstraße 1')['status'] ?? null);
    }

    public function testApproximateShowsItsLabelAndTheHintLinksToTheData(): void
    {
        $id = $this->mitglied(101, 'Am Hang 12');
        $this->geoStatus('Am Hang 12', GeoStatus::Ungefaehr, 50.0329, 9.1424);
        $this->als(Rolle::Vorstand);

        $html = $this->daten($id);
        self::assertStringContainsString('<span class="etikett etikett-warnung">ungefähr</span> Die Straße wurde nicht gefunden, nur PLZ und Ort.', $html);
        self::assertStringContainsString('data-genauigkeit="ungefaehr"', $html);
        self::assertStringContainsString('Die Anschrift wurde auf der Karte nur ungefähr gefunden', $html, 'the hint at the top');
        self::assertStringNotContainsString('Zu „Wohnort auf der Karte“ im Reiter „Daten“', $html, 'already on the tab');

        $andererReiter = $this->get('/app/mitglieder', ['mitglied' => (string) $id, 'reiter' => 'verlauf'])->body;
        self::assertStringContainsString('Zu „Wohnort auf der Karte“ im Reiter „Daten“', $andererReiter);
    }

    public function testWithoutADotTheSmallMapOpensWhereOtherMembersOfThePostcodeLive(): void
    {
        $this->mitglied(101, 'Hauptstraße 1');
        $this->mitglied(102, 'Bahnhofstraße 3');
        $id = $this->mitglied(103, 'Am Hang 12');
        $this->geoStatus('Hauptstraße 1', GeoStatus::Gefunden, 50.0, 9.0);
        $this->geoStatus('Bahnhofstraße 3', GeoStatus::VonHand, 50.2, 9.2);
        $this->als(Rolle::Vorstand);

        self::assertStringContainsString('data-mitte-lat="50.100000" data-mitte-lon="9.100000" data-mitte-zoom="14"', $this->daten($id));
        self::assertSame([], $this->http->anfragen, 'no external request for the middle');
    }
}
