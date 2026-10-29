<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\AbteilungRepository;
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

    public function testOpeningAMemberShowsItBesideTheList(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->idVon(1001);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id])->body;

        self::assertStringContainsString('class="geteilt geteilt-mit-detail"', $seite);
        self::assertStringContainsString('<h2>Erika Mustermann</h2>', $seite);
        self::assertStringContainsString('Nr. 1001', $seite);
        self::assertStringContainsString('Beispielweg 1, 12345 Beispielstadt', $seite);
        self::assertStringContainsString('aria-current="true"', $seite, 'the list marks the opened member');
        self::assertStringContainsString('Zurück zur Liste', $seite);
    }

    public function testAClickOnAMemberSwapsTheWholeArea(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->idVon(1002);

        $antwort = $this->htmx(['text' => 'karl', 'ehemalige' => '0'], 'mitglieder-bereich', ['mitglied' => (string) $id]);

        self::assertStringContainsString('<div class="geteilt geteilt-mit-detail" id="mitglieder-bereich">', $antwort->body);
        self::assertStringContainsString('<h2>Karl Beispiel</h2>', $antwort->body);
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
}
