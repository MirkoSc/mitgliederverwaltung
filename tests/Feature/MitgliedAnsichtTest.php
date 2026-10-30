<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\Einbettung;
use App\App\Mitglied\MitgliedAnsicht;
use App\App\Mitglied\MitgliedFormular;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The opened member as a building block (docs/spec/06-oberflaeche.md
 * section 5, issue #86): embedded on a second page - here a made-up list
 * address - every tab, "Schließen", the forms and the redirects after a save
 * lead back to that page, never to the members page. Made-up data only.
 */
final class MitgliedAnsichtTest extends AppTestCase
{
    private const string BASIS = '/app/listen/geburtstage?zeitraum=monat';

    private ?\App\Domain\Konto $konto = null;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    private function mitglied(int $nummer = 1, string $nachname = 'Musterfrau'): Mitglied
    {
        return $this->repo()->speichern(new Mitglied($nummer, $nachname, vorname: 'Erika', ort: 'Beispielstadt'), VersionsAnlass::angelegt(), null)->mitglied;
    }

    private function einbettung(): Einbettung
    {
        return new Einbettung('/app/listen/geburtstage', ['zeitraum' => 'monat'])->mitHtmx('listen-bereich');
    }

    /**
     * Renders the building block outside a request, as the account the
     * test logged in (the guard sets it on the view in a real request).
     */
    private function zeigen(int $id, string $reiter = '', ?string $fokus = 'mitglied'): string
    {
        $this->dienste->view->setAnmeldung($this->konto, 'token');
        $ansicht = MitgliedAnsicht::aus($this->dienste)->zeigen($id, $reiter, $fokus, $this->einbettung());
        self::assertTrue($ansicht->istOffen());

        return $ansicht->html;
    }

    /**
     * Every address in the HTML that leads to the members page itself (not
     * to a route of the member, /app/mitglieder/{id}/...).
     *
     * @return list<string>
     */
    private static function linksAufDieMitgliederSeite(string $html): array
    {
        preg_match_all('#/app/mitglieder(?:\?[^"\']*)?(?=["\'])#', $html, $treffer);

        return $treffer[0];
    }

    public function testTabsAndClosingLeadBackToTheEmbeddingPage(): void
    {
        $this->konto = $this->als(Rolle::Admin);
        $id = (int) $this->mitglied()->id;

        $html = $this->zeigen($id);

        self::assertStringStartsWith('<div class="geteilt-detail">', trim($html));
        foreach (['daten', 'familie', 'beitraege', 'nachweise', 'verlauf'] as $reiter) {
            self::assertStringContainsString(
                'hx-get="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $id . '&amp;reiter=' . $reiter . '"',
                $html,
                "tab $reiter",
            );
        }
        self::assertStringContainsString('hx-target="#listen-bereich"', $html);
        self::assertMatchesRegularExpression(
            '#<a class="knopf knopf-still detail-schliessen" href="/app/listen/geburtstage\?zeitraum=monat" hx-get="/app/listen/geburtstage\?zeitraum=monat"#',
            $html,
            '"Schließen" keeps the page and drops the member',
        );
        self::assertSame([], self::linksAufDieMitgliederSeite($html), 'nothing in the member leads to the members page');
    }

    public function testFormsCarryThePageAndOwnPagesGetItInTheQuery(): void
    {
        $this->konto = $this->als(Rolle::Admin);
        $id = (int) $this->mitglied()->id;
        $feld = '<input type="hidden" name="zurueck" value="/app/listen/geburtstage?zeitraum=monat">';

        $daten = $this->zeigen($id, 'daten');
        self::assertStringContainsString($feld, $daten, 'the member form');
        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $id . '">Änderungen verwerfen<', $daten);
        self::assertStringContainsString('href="/app/mitglieder/' . $id . '/loeschen?zurueck=%2Fapp%2Flisten%2Fgeburtstage%3Fzeitraum%3Dmonat"', $daten);

        $familie = $this->zeigen($id, 'familie');
        self::assertStringContainsString('href="/app/mitglieder/' . $id . '/familie?zurueck=%2Fapp%2Flisten%2Fgeburtstage%3Fzeitraum%3Dmonat"', $familie);

        self::assertStringContainsString($feld, $this->zeigen($id, 'nachweise'));
        self::assertStringContainsString(
            '/app/beitraege',
            $this->zeigen($id, 'beitraege'),
        );
    }

    public function testTheHintLinkAndTheOtherFamilyMembersStayOnThePage(): void
    {
        $this->konto = $this->als(Rolle::Vorstand);
        $familie = new FamilieRepository($this->dienste->pdo())->anlegen('Familie Muster');
        $zahler = $this->repo()->speichern(new Mitglied(1, 'Muster', vorname: 'Paul', strasse: 'Am Hang 1', plz: '12345', ort: 'Beispielstadt', familieId: $familie->id, familieRolle: FamilienRolle::Zahler), VersionsAnlass::angelegt(), null)->mitglied;
        $kind = $this->repo()->speichern(new Mitglied(2, 'Muster', vorname: 'Lena', strasse: 'Andere Straße 9', plz: '12345', ort: 'Beispielstadt', familieId: $familie->id, familieRolle: FamilienRolle::Kind), VersionsAnlass::angelegt(), null)->mitglied;

        $html = $this->zeigen((int) $kind->id, 'familie');

        self::assertStringContainsString('hx-get="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $zahler->id . '"', $html, 'another person of the family opens on the same page');
        if (str_contains($html, 'Zu den Daten, um die Anschrift zu übernehmen')) {
            self::assertStringContainsString('hx-get="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $kind->id . '&amp;reiter=daten"', $html);
        }
        self::assertSame([], self::linksAufDieMitgliederSeite($html));
    }

    public function testSavingRedirectsToTheEmbeddingPageOnTheSameTab(): void
    {
        $this->konto = $this->als(Rolle::Admin);
        $m = $this->mitglied();
        $id = (int) $m->id;
        $formular = MitgliedFormular::aus($m);

        $antwort = $this->post('/app/mitglieder/' . $id, [
            ...$formular->werte,
            'abteilungen' => array_map(strval(...), $formular->abteilungIds),
            'funktionen' => array_map(strval(...), $formular->funktionIds),
            'version' => (string) $m->version,
            'ort' => 'Neustadt',
            'zurueck' => self::BASIS,
        ]);
        self::assertWeiterleitungNach(self::BASIS . '&mitglied=' . $id, $antwort);

        self::assertWeiterleitungNach(
            self::BASIS . '&mitglied=' . $id . '&reiter=familie',
            $this->post("/app/mitglieder/$id/familie/neu", ['name' => 'Familie Muster', 'rolle' => 'zahler', 'zurueck' => self::BASIS]),
        );
        self::assertWeiterleitungNach(
            self::BASIS . '&mitglied=' . $id . '&reiter=nachweise',
            $this->post("/app/mitglieder/$id/nachweise", ['art' => 'studium', 'zurueck' => self::BASIS]),
        );
        self::assertWeiterleitungNach(
            self::BASIS . '&mitglied=' . $id . '&reiter=verlauf',
            $this->post("/app/mitglieder/$id/verlauf/1/zurueckholen", ['zurueck' => self::BASIS]),
        );
    }

    public function testAFailedSaveKeepsThePageForTheNextTry(): void
    {
        $this->konto = $this->als(Rolle::Admin);
        $m = $this->mitglied();
        $formular = MitgliedFormular::aus($m);

        $antwort = $this->post('/app/mitglieder/' . $m->id, [
            ...$formular->werte,
            'version' => (string) $m->version,
            'nachname' => '',
            'zurueck' => self::BASIS,
        ]);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('<input type="hidden" name="zurueck" value="/app/listen/geburtstage?zeitraum=monat">', $antwort->body);
        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $m->id . '">Änderungen verwerfen<', $antwort->body);
    }

    public function testDeletingAsksOnItsOwnPageAndReturnsToTheList(): void
    {
        $this->konto = $this->als(Rolle::Admin);
        $id = (int) $this->mitglied()->id;

        $frage = $this->get("/app/mitglieder/$id/loeschen", ['zurueck' => self::BASIS])->body;
        self::assertStringContainsString('<input type="hidden" name="zurueck" value="/app/listen/geburtstage?zeitraum=monat">', $frage);
        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $id . '">Nicht löschen', $frage);

        self::assertWeiterleitungNach(self::BASIS, $this->post("/app/mitglieder/$id/loeschen", ['zurueck' => self::BASIS]));
    }

    public function testChoosingAFamilyLeadsBackToTheEmbeddingPage(): void
    {
        $this->konto = $this->als(Rolle::Vorstand);
        $id = (int) $this->mitglied()->id;

        $seite = $this->get("/app/mitglieder/$id/familie", ['zurueck' => self::BASIS])->body;

        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=' . $id . '&amp;reiter=familie">Zurück zum Mitglied<', $seite);
        self::assertSame(2, substr_count($seite, '<input type="hidden" name="zurueck" value="/app/listen/geburtstage?zeitraum=monat">'), 'the search and "Neue Familie" (no family to join yet)');
    }

    public function testAnUnsafeReturnAddressFallsBackToTheMembersPage(): void
    {
        $this->konto = $this->als(Rolle::Admin);
        $id = (int) $this->mitglied()->id;

        foreach (['https://boese.example/', '//boese.example/app', '/admin/benutzer'] as $boese) {
            self::assertWeiterleitungNach(
                '/app/mitglieder?mitglied=' . $id . '&reiter=verlauf',
                $this->post("/app/mitglieder/$id/verlauf/1/zurueckholen", ['zurueck' => $boese]),
            );
        }
    }

    public function testRightsStayAsTheyAre(): void
    {
        $id = (int) $this->mitglied()->id;

        $this->konto = $this->als(Rolle::Vorstand);
        $vorstand = $this->zeigen($id);
        self::assertStringContainsString('reiter=beitraege', $vorstand, 'the Vorstand sees the paid status');
        self::assertStringNotContainsString('Mitglied löschen', $vorstand);

        $this->konto = $this->als(Rolle::Admin);
        self::assertStringContainsString('reiter=beitraege', $this->zeigen($id));
    }

    public function testFocusGoesToTheHeadingOrTheChosenTab(): void
    {
        $this->konto = $this->als(Rolle::Vorstand);
        $id = (int) $this->mitglied()->id;

        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1" autofocus>Erika Musterfrau</h2>', $this->zeigen($id));

        $reiter = $this->zeigen($id, 'verlauf', 'reiter');
        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1">', $reiter);
        self::assertMatchesRegularExpression('#reiter=verlauf"[^>]*aria-current="page" autofocus>Verlauf</a>#', $reiter);

        self::assertStringNotContainsString('autofocus', $this->zeigen($id, 'verlauf', null), 'sorting the list does not move the focus');
    }

    public function testTheMembersPageAsksForTheFocusOnlyWhenAMemberOpens(): void
    {
        $this->als(Rolle::Vorstand, barrierefrei: true);
        $id = (int) $this->mitglied()->id;
        $this->mitglied(2, 'Beispiel');
        $session = new Session();
        $session->start();
        $htmx = static fn(array $felder): Request => new Request(
            HttpMethod::Post,
            '/app/mitglieder',
            query: ['mitglied' => (string) $id],
            post: ['ehemalige' => '0', ...$felder],
            headers: ['hx-request' => 'true', 'hx-target' => 'mitglieder-bereich', 'x-csrf-token' => $session->csrfToken()],
        );

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id])->body;
        self::assertStringContainsString('modus-barrierefrei', $seite);
        self::assertStringContainsString('tabindex="-1" autofocus>Erika Musterfrau<', $seite);
        self::assertMatchesRegularExpression('#<a href="/app/mitglieder\?mitglied=' . $id . '&amp;reiter=familie"[^>]*>Familie</a>#', $seite, 'tabs are real links, usable by keyboard');
        self::assertStringContainsString("hx-vals='{&quot;fokus&quot;:&quot;mitglied&quot;}'", $seite, 'the list asks for the focus when it opens another member');

        self::assertStringContainsString('tabindex="-1" autofocus>', $this->aufrufen($htmx(['fokus' => 'mitglied']))->body);
        self::assertStringNotContainsString('autofocus', $this->aufrufen($htmx([]))->body);
    }

    public function testAfterASaveTheMessageKeepsTheFocusAtTheTop(): void
    {
        $this->als(Rolle::Vorstand);
        $id = (int) $this->mitglied()->id;
        $this->dienste->session->flash('Mitglied gespeichert.');

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id])->body;

        self::assertStringContainsString('Mitglied gespeichert.', $seite);
        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1">', $seite);
    }

    public function testANewMemberFromAFilteredListReturnsToIt(): void
    {
        $this->als(Rolle::Vorstand);

        $liste = $this->get('/app/mitglieder', ['status' => 'aktiv'])->body;
        preg_match('#href="(/app/mitglieder/neu[^"]*)">Neues Mitglied#', $liste, $treffer);
        self::assertNotEmpty($treffer);
        self::assertStringContainsString('zurueck=', $treffer[1]);

        $formular = $this->get('/app/mitglieder/neu', ['zurueck' => '/app/mitglieder?status=aktiv'])->body;
        self::assertStringContainsString('<input type="hidden" name="zurueck" value="/app/mitglieder?status=aktiv">', $formular);

        $antwort = $this->post('/app/mitglieder/neu', ['mitgliedsnummer' => '77', 'nachname' => 'Neumann', 'zurueck' => '/app/mitglieder?status=aktiv']);
        self::assertSame(302, $antwort->status);
        self::assertMatchesRegularExpression('#^/app/mitglieder\?status=aktiv&mitglied=\d+$#', $antwort->headers['Location'] ?? '');
    }

    public function testAnUnknownMemberIsNoError(): void
    {
        $this->konto = $this->als(Rolle::Vorstand);

        $ansicht = MitgliedAnsicht::aus($this->dienste)->zeigen(999, '', null, $this->einbettung());

        self::assertFalse($ansicht->istOffen());
        self::assertTrue($ansicht->unbekannt);
        self::assertSame('', $ansicht->klasse());
        self::assertSame('<div class="geteilt-detail">', trim(explode("\n", trim($ansicht->html))[0]));
    }
}
