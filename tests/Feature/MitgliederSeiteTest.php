<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The page "Mitglieder" through the whole application (docs/spec/
 * 06-oberflaeche.md sections 2 and 3, issue #18): list, live search by
 * htmx, filters, the opened member. Made-up data only.
 */
final class MitgliederSeiteTest extends AppTestCase
{
    private \PDO $pdo;

    protected function pdoFabrik(): ?\Closure
    {
        $this->pdo = ($this->mitDatenbank())();

        return fn(): \PDO => $this->pdo;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $repo = new MitgliedRepository($this->pdo);
        $fussball = (int) new AbteilungRepository($this->pdo)->alle()[1]->id;
        foreach ([
            new Mitglied(1001, 'Mustermann', vorname: 'Erika', ort: 'Beispielstadt', plz: '12345', abteilungIds: [$fussball], strasse: 'Beispielweg 1'),
            new Mitglied(1002, 'Beispiel', vorname: 'Karl', ort: 'Musterhausen', aktiv: false),
            new Mitglied(1003, 'Altmann', vorname: 'Hans', austrittsdatum: new \DateTimeImmutable('2020-01-31')),
        ] as $mitglied) {
            $repo->speichern($mitglied, VersionsAnlass::angelegt(), 1);
        }
    }

    private function idVon(int $nummer): int
    {
        return (int) new MitgliedRepository($this->pdo)->findeNachNummer($nummer)?->id;
    }

    /**
     * What the search form and the links of the list send: htmx, a POST
     * with the session's token and the id of the element to swap.
     *
     * @param array<string, string> $felder
     * @param array<string, string> $abfrage
     */
    private function htmx(array $felder, string $ziel, array $abfrage = []): Response
    {
        $session = new Session();
        $session->start();

        return $this->aufrufen(new Request(
            HttpMethod::Post,
            '/app/mitglieder',
            query: $abfrage,
            post: $felder,
            headers: ['hx-request' => 'true', 'hx-target' => $ziel, 'x-csrf-token' => $session->csrfToken()],
        ));
    }

    public function testBothRolesMaySeeTheList(): void
    {
        foreach ([Rolle::Vorstand, Rolle::Admin] as $rolle) {
            $this->als($rolle);
            $seite = $this->get('/app/mitglieder');

            self::assertSame(200, $seite->status);
            self::assertStringContainsString('<h1>Mitglieder</h1>', $seite->body);
            self::assertStringContainsString('Mustermann, Erika', $seite->body);
            self::assertStringContainsString('role="search"', $seite->body);
        }
    }

    public function testFormerMembersAreHiddenUntilAskedFor(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertStringNotContainsString('Altmann', $this->get('/app/mitglieder')->body);
        self::assertStringContainsString('(ohne Ehemalige)', $this->get('/app/mitglieder')->body);

        $mit = $this->get('/app/mitglieder', ['ehemalige' => '1'])->body;
        self::assertStringContainsString('Altmann, Hans', $mit);
        self::assertStringContainsString('etikett-fehler">ehemalig<', $mit);
        self::assertStringContainsString('checked', $mit);
    }

    public function testTheFormIsAnOrdinaryFormWithoutAScript(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder')->body;

        self::assertStringContainsString('<form class="suche" id="mitglieder-form" method="post" action="/app/mitglieder"', $seite);
        self::assertMatchesRegularExpression('/name="_csrf" value="[0-9a-f]{20,}"/', $seite);
        self::assertStringContainsString('<label for="suche-text">Mitglied suchen</label>', $seite);
        self::assertStringContainsString('<button type="submit" class="knopf">Suchen</button>', $seite);
    }

    public function testTheLiveSearchAnswersWithTheResultListOnly(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->htmx(['text' => 'musterhausen', 'ehemalige' => '0'], 'mitglieder-ergebnis');

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Beispiel, Karl', $antwort->body);
        self::assertStringNotContainsString('Mustermann', $antwort->body);
        self::assertStringNotContainsString('<html', $antwort->body, 'a fragment, not a page');
        self::assertStringNotContainsString('role="search"', $antwort->body, 'the form stays where it is');
        // The sentence for the screen reader is updated beside the list.
        self::assertStringContainsString('hx-swap-oob="innerHTML:#suche-stand">1 Mitglied gefunden.<', $antwort->body);
    }

    /** CLAUDE.md section 4: a name does not belong into a URL, a log or a bookmark. */
    public function testTheSearchTextNeverAppearsInAnAddress(): void
    {
        $this->als(Rolle::Vorstand);
        $antwort = $this->htmx(['text' => 'Mustermann', 'ehemalige' => '1'], 'mitglieder-ergebnis');

        self::assertStringContainsString('Mustermann, Erika', $antwort->body);
        preg_match_all('/(?:href|hx-post|action)="([^"]*)"/', $antwort->body, $adressen);
        self::assertNotSame([], $adressen[1]);
        foreach ($adressen[1] as $adresse) {
            self::assertStringNotContainsString('Mustermann', $adresse);
            self::assertStringNotContainsString('text=', $adresse);
        }

        $ganze = $this->htmx(['text' => 'Mustermann'], 'mitglieder-bereich');
        preg_match_all('/(?:href|hx-post|action)="([^"]*)"/', $ganze->body, $adressen);
        foreach ($adressen[1] as $adresse) {
            self::assertStringNotContainsString('Mustermann', $adresse);
        }
        // ... but the field keeps it, so the next request carries it again.
        self::assertStringContainsString('value="Mustermann"', $ganze->body);
    }

    public function testFiltersCombineInTheAnswer(): void
    {
        $this->als(Rolle::Vorstand);
        $fussball = (string) new AbteilungRepository($this->pdo)->alle()[1]->id;

        $beides = $this->htmx(['abteilung' => $fussball, 'status' => 'aktiv', 'ehemalige' => '0'], 'mitglieder-ergebnis')->body;
        self::assertStringContainsString('Mustermann', $beides);
        self::assertStringNotContainsString('Beispiel, Karl', $beides);

        $keiner = $this->htmx(['abteilung' => $fussball, 'status' => 'passiv', 'ehemalige' => '0'], 'mitglieder-ergebnis')->body;
        self::assertStringContainsString('Kein Mitglied passt zu dieser Suche.', $keiner);
        self::assertStringContainsString('Ehemalige Mitglieder sind ausgeblendet.', $keiner);
    }

    public function testSortingAndPagingLinksKeepTheStateButNotTheText(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->htmx(['text' => 'e', 'status' => 'alle', 'ehemalige' => '0'], 'mitglieder-ergebnis', ['sortierung' => 'nummer']);

        self::assertStringContainsString('aria-sort="ascending"><a href="/app/mitglieder?sortierung=nummer-ab"', $antwort->body);
        self::assertStringContainsString('hx-post="/app/mitglieder?sortierung=name"', $antwort->body, 'the name column sorts ascending');
        self::assertStringContainsString('hx-include="#mitglieder-form"', $antwort->body);
    }

    public function testSortingSwapsTheWholeAreaSoTheFormKeepsTheSortOrder(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder', ['sortierung' => 'ort'])->body;
        self::assertStringContainsString('hx-post="/app/mitglieder?sortierung=ort"', $seite, 'the form carries the sort order');
        self::assertMatchesRegularExpression('/<a href="[^"]*sortierung=ort-ab"[^>]*hx-target="#mitglieder-bereich" hx-swap="outerHTML"/', $seite);

        // Sorting answers with the whole area: the search form in it has the new order.
        $antwort = $this->htmx(['text' => 'muster', 'status' => 'alle', 'ehemalige' => '0'], 'mitglieder-bereich', ['sortierung' => 'ort']);
        self::assertStringContainsString('id="mitglieder-form"', $antwort->body);
        self::assertStringContainsString('<form class="suche" id="mitglieder-form" method="post" action="/app/mitglieder?sortierung=ort"', $antwort->body);
        self::assertStringContainsString('value="muster"', $antwort->body, 'the search text stays');
    }

    public function testOpeningAMemberPushesTheAddressWithoutTheText(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->idVon(1001);

        $antwort = $this->htmx(['text' => 'muster', 'ehemalige' => '0'], 'mitglieder-bereich', ['mitglied' => (string) $id]);

        self::assertStringContainsString('hx-push-url="/app/mitglieder?mitglied=' . $id . '&amp;reiter=familie"', $antwort->body);
        self::assertStringNotContainsString('hx-push-url="/app/mitglieder?mitglied=' . $id . '&amp;text', $antwort->body);
        self::assertStringNotContainsString('text=muster', $antwort->body);
    }

    public function testOpeningAMemberShowsItBesideTheList(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->idVon(1001);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id])->body;

        self::assertStringContainsString('class="geteilt geteilt-mit-detail"', $seite);
        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1" autofocus>Erika Mustermann</h2>', $seite, 'a whole page puts the focus on the member');
        self::assertStringContainsString('Nr. 1001', $seite);
        self::assertStringContainsString('aria-current="page">Daten<', $seite, 'the tab "Daten" is open');
        self::assertStringContainsString('id="mitglied-formular"', $seite);
        self::assertStringContainsString('aria-current="true"', $seite, 'the list marks the opened member');
        self::assertStringContainsString('Zurück zur Liste', $seite);
        self::assertStringContainsString('hx-push-url="/app/mitglieder"', $seite, 'back to the list leaves the member out of the history address');
    }

    public function testAClickOnAMemberSwapsTheWholeArea(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->idVon(1002);

        $antwort = $this->htmx(['text' => 'karl', 'ehemalige' => '0'], 'mitglieder-bereich', ['mitglied' => (string) $id]);

        self::assertStringContainsString('<div class="geteilt geteilt-mit-detail" id="mitglieder-bereich">', $antwort->body);
        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1">Karl Beispiel</h2>', $antwort->body, 'without fokus (sorting) htmx leaves the focus alone');
        self::assertStringContainsString('etikett ">passiv<', $antwort->body);
        self::assertStringContainsString('value="karl"', $antwort->body, 'the search stays when a member opens');
        self::assertStringNotContainsString('<html', $antwort->body);
    }

    public function testAnUnknownMemberLeavesTheListUsable(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder', ['mitglied' => '999999']);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Dieses Mitglied gibt es nicht mehr.', $seite->body);
        self::assertStringNotContainsString('geteilt-mit-detail', $seite->body);
        self::assertStringContainsString('Mustermann, Erika', $seite->body);
    }

    public function testWithoutHtmxAPostShowsTheWholePage(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/mitglieder', ['text' => 'karl', 'ehemalige' => '0']);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('<!DOCTYPE html>', $antwort->body);
        self::assertStringContainsString('Beispiel, Karl', $antwort->body);
        self::assertStringNotContainsString('Mustermann', $antwort->body);
    }

    public function testASearchWithoutTheTokenIsRefused(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/app/mitglieder', ['text' => 'karl'], mitToken: false)->status);
    }

    public function testAnonymousVisitorsGetTheLoginNotTheList(): void
    {
        $antwort = $this->get('/app/mitglieder');

        self::assertSame(302, $antwort->status);
        self::assertStringStartsWith('/anmelden', $antwort->headers['Location'] ?? '');
    }

    public function testTheListAsksNothingOfTheBarrierefreiMode(): void
    {
        $this->als(Rolle::Vorstand, barrierefrei: true);

        $seite = $this->get('/app/mitglieder')->body;

        self::assertStringContainsString('<html lang="de" class="modus-barrierefrei">', $seite);
        self::assertStringContainsString('class="nebenspalte"', $seite, 'the side columns are marked; the stylesheet drops them');
    }

    public function testWithoutAMemberTheListHasTheWholeWidth(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder')->body;

        self::assertStringContainsString('<div class="geteilt" id="mitglieder-bereich">', $seite);
        self::assertStringNotContainsString('class="detail-kopf"', $seite, 'no empty detail beside the list');
    }

    public function testTheOpenMemberCanBeClosedAgain(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->idVon(1001);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id])->body;

        // "Schließen" in the head, and a second click on the marked row.
        self::assertMatchesRegularExpression('#class="knopf knopf-still detail-schliessen" href="/app/mitglieder"[^>]*>Schließen</a>#', $seite);
        self::assertMatchesRegularExpression('#<a href="/app/mitglieder"[^>]*aria-current="true">Mustermann, Erika</a>#', $seite);
        self::assertMatchesRegularExpression('#<a href="/app/mitglieder\?mitglied=' . $this->idVon(1002) . '"[^>]*>Beispiel, Karl</a>#', $seite, 'the others still open');
    }

    public function testTheTabsAreDatenFamilieBeitraegeNachweiseVerlauf(): void
    {
        $id = (string) $this->idVon(1001);
        $reiter = static function (string $seite): array {
            preg_match('#<nav class="reiter".*?</nav>#s', $seite, $nav);
            preg_match_all('#>([^<>]+)</a></li>#', $nav[0] ?? '', $titel);

            return $titel[1];
        };

        $this->als(Rolle::Admin);
        self::assertSame(['Daten', 'Familie', 'Beiträge', 'Nachweise', 'Verlauf'], $reiter($this->get('/app/mitglieder', ['mitglied' => $id])->body));

        $this->als(Rolle::Vorstand);
        $seite = $this->get('/app/mitglieder', ['mitglied' => $id])->body;
        self::assertSame(['Daten', 'Familie', 'Nachweise', 'Verlauf'], $reiter($seite));
        self::assertStringNotContainsString('id="familie-titel"', $seite, 'the family is no longer above the tabs');

        $familie = $this->get('/app/mitglieder', ['mitglied' => $id, 'reiter' => 'familie'])->body;
        self::assertStringContainsString('id="familie-titel"', $familie);
        self::assertStringContainsString('Keine Familie.', $familie);
        self::assertStringContainsString('id="nachweise-titel"', $this->get('/app/mitglieder', ['mitglied' => $id, 'reiter' => 'nachweise'])->body);
    }

    public function testTheAddressHintShowsThePayersAddressAndOffersToCopyIt(): void
    {
        $repo = new MitgliedRepository($this->pdo);
        $familie = new \App\Repository\FamilieRepository($this->pdo)->anlegen('Familie Mustermann');
        $zahler = $repo->speichern(new Mitglied(1010, 'Mustermann', vorname: 'Max', strasse: 'Hauptstraße 5', plz: '54321', ort: 'Zahlerdorf',
            familieId: $familie->id, familieRolle: \App\Domain\FamilienRolle::Zahler), VersionsAnlass::angelegt(), 1)->mitglied;
        $kind = $repo->speichern(new Mitglied(1011, 'Mustermann', vorname: 'Mia', strasse: 'Nebenweg 2', plz: '12345', ort: 'Anderswo',
            familieId: $familie->id, familieRolle: \App\Domain\FamilienRolle::Kind), VersionsAnlass::angelegt(), 1)->mitglied;
        self::assertNotNull($zahler->id);
        $this->als(Rolle::Vorstand);

        $daten = $this->get('/app/mitglieder', ['mitglied' => (string) $kind->id])->body;
        self::assertStringContainsString('Anschrift des Zahlers (Max Mustermann):', $daten);
        self::assertStringContainsString('Hauptstraße 5', $daten);
        self::assertStringContainsString('54321 Zahlerdorf', $daten);
        self::assertMatchesRegularExpression('#data-anschrift-uebernehmen\s+data-strasse="Hauptstraße 5" data-plz="54321"\s+data-ort="Zahlerdorf" data-land="D"#', $daten);
        self::assertStringContainsString('src="/js/anschrift.js', $daten);
        self::assertSame('Nebenweg 2', $repo->finde((int) $kind->id)?->strasse, 'nothing is saved by showing it');

        // On another tab the form is not there: the button leads to "Daten".
        $familieReiter = $this->get('/app/mitglieder', ['mitglied' => (string) $kind->id, 'reiter' => 'familie'])->body;
        self::assertStringNotContainsString('data-anschrift-uebernehmen', $familieReiter);
        self::assertStringContainsString('reiter=daten"', $familieReiter);
        self::assertStringContainsString('Zu den Daten, um die Anschrift zu übernehmen', $familieReiter);

        // The payer gets no such hint about himself.
        self::assertStringNotContainsString('Anschrift des Zahlers', $this->get('/app/mitglieder', ['mitglied' => (string) $zahler->id])->body);
    }

    public function testThePageSizeIsADropdownInThePagerAndKeptInTheLinks(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder', ['anzahl' => 'alle'])->body;

        self::assertStringContainsString('<select id="filter-anzahl" name="anzahl" form="mitglieder-form">', $seite);
        self::assertStringContainsString('<option value="alle" selected>Alle</option>', $seite);
        self::assertStringContainsString('<option value="50">50</option>', $seite);
        self::assertStringContainsString('change from:#mitglieder-ergebnis', $seite);
        self::assertStringContainsString('href="/app/mitglieder?anzahl=alle&amp;mitglied=' . $this->idVon(1001) . '"', $seite, 'opening a member keeps the size');
        // It is no longer one of the filters above the list.
        self::assertSame(1, substr_count($seite, 'id="filter-anzahl"'));
        self::assertLessThan(strpos($seite, 'id="filter-anzahl"'), strpos($seite, 'class="blaettern"'));
    }

    /** Between "Vorherige" and "Nächste", beside "Seite x von y" - and usable without a script. */
    public function testThePagerHoldsThePageSizeBetweenThePageButtons(): void
    {
        $this->als(Rolle::Vorstand);
        $repo = new MitgliedRepository($this->pdo);
        for ($i = 1; $i <= 60; $i++) {
            $repo->speichern(new Mitglied(2000 + $i, sprintf('Test%03d', $i)), VersionsAnlass::angelegt(), 1);
        }

        $seite = $this->get('/app/mitglieder')->body;
        $nav = substr($seite, (int) strpos($seite, '<nav class="blaettern"'));
        $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));

        $vorherige = strpos($nav, 'Vorherige Seite');
        $stand = strpos($nav, 'Seite 1 von 2');
        $select = strpos($nav, '<select id="filter-anzahl"');
        $naechste = strpos($nav, 'Nächste Seite');
        self::assertNotFalse($vorherige);
        self::assertTrue($vorherige < $stand && $stand < $select && $select < $naechste, 'order in the pager');
        self::assertStringContainsString('<label for="filter-anzahl">Je Seite</label>', $nav);
        self::assertStringContainsString('<button type="submit" form="mitglieder-form" class="knopf knopf-still">Übernehmen</button>', $nav);
        self::assertStringNotContainsString('je Seite 50', $nav, 'the select says it');

        // A larger size with one page keeps the pager, or "Alle" could not be left again.
        $alle = $this->get('/app/mitglieder', ['anzahl' => 'alle'])->body;
        self::assertStringContainsString('<nav class="blaettern"', $alle);
        self::assertStringContainsString('Seite 1 von 1', $alle);
        // With the default size and one page there is no pager.
        $klein = $this->get('/app/mitglieder', ['abteilung' => (string) new AbteilungRepository($this->pdo)->alle()[1]->id])->body;
        self::assertStringNotContainsString('<nav class="blaettern"', $klein);
    }

    public function testTheSearchFieldSaysWhatIsSearched(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder')->body;

        self::assertStringContainsString('aria-describedby="suche-hilfe"', $seite);
        self::assertMatchesRegularExpression('/id="suche-hilfe">Sucht in [^<]*IBAN[^<]*Geburtsdatum[^<]*Bemerkung/', $seite);
    }

    public function testSearchingByDateOfBirthAndAddressThroughTheForm(): void
    {
        $this->als(Rolle::Vorstand);
        new MitgliedRepository($this->pdo)->speichern(
            new Mitglied(1004, 'Probe', vorname: 'Anna', strasse: 'Hauptstraße 12', ort: 'Musterort', geburtsdatum: new \DateTimeImmutable('1980-02-01')),
            VersionsAnlass::angelegt(),
            1,
        );

        $antwort = $this->htmx(['text' => 'Anna 1.2.1980 Hauptstr. 12', 'ehemalige' => '0'], 'mitglieder-ergebnis');

        self::assertStringContainsString('Probe, Anna', $antwort->body);
        self::assertStringNotContainsString('Mustermann', $antwort->body);
        self::assertStringContainsString('1 Mitglied gefunden.', $antwort->body);
    }

    public function testFamilyMembersFoundThroughThePayerAreMarked(): void
    {
        $this->als(Rolle::Vorstand);
        $repo = new MitgliedRepository($this->pdo);
        $familie = new FamilieRepository($this->pdo)->anlegen('Familie Muster');
        $art = null;
        foreach (new BeitragsartRepository($this->pdo)->alle() as $beitragsart) {
            $art = $beitragsart->name === 'Familie' ? $beitragsart->id : $art;
        }
        $repo->speichern(new Mitglied(3001, 'Muster', vorname: 'Paula', beitragsartId: $art, familieId: $familie->id, familieRolle: FamilienRolle::Zahler), VersionsAnlass::angelegt(), 1);
        $repo->speichern(new Mitglied(3002, 'Muster', vorname: 'Tom', familieId: $familie->id, familieRolle: FamilienRolle::Kind), VersionsAnlass::angelegt(), 1);

        $antwort = $this->htmx(['beitragsart' => (string) $art, 'ehemalige' => '0'], 'mitglieder-ergebnis');

        self::assertStringContainsString('Muster, Paula', $antwort->body);
        self::assertStringContainsString('Muster, Tom</a> <span class="klein gedaempft">über Familie Muster</span>', $antwort->body);
        self::assertSame(1, substr_count($antwort->body, 'über Familie Muster'), 'the payer is not marked');
        self::assertStringContainsString('2 Mitglieder gefunden.', $antwort->body);
    }
}
