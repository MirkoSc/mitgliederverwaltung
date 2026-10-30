<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\MitgliedFormular;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Repository\FamilieRepository;
use App\Repository\FamilienListeRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The page "Familien" (issue #92): the list with the address hint, the
 * family with its persons, the changes (role, payer, add, remove, rename,
 * new, delete an empty one), "Anschrift übernehmen", the member opened in
 * place of the family, and the rights. All names and addresses are made up.
 */
final class FamilienSeiteTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    private function familie(string $name = 'Familie Muster'): int
    {
        return new FamilieRepository($this->dienste->pdo())->anlegen($name)->id;
    }

    private function person(
        int $nummer,
        string $vorname,
        ?int $familieId = null,
        ?FamilienRolle $rolle = null,
        string $strasse = 'Am Hang 1',
        string $plz = '12345',
        string $ort = 'Beispielstadt',
    ): Mitglied {
        return $this->repo()->speichern(new Mitglied(
            $nummer,
            'Muster',
            vorname: $vorname,
            strasse: $strasse,
            plz: $plz,
            ort: $ort,
            familieId: $familieId,
            familieRolle: $rolle,
        ), VersionsAnlass::angelegt(), null)->mitglied;
    }

    /** @return array{0: int, 1: Mitglied, 2: Mitglied, 3: Mitglied} family, payer, parent, child */
    private function musterfamilie(): array
    {
        $f = $this->familie();
        $zahler = $this->person(1, 'Paul', $f, FamilienRolle::Zahler);
        $eltern = $this->person(2, 'Petra', $f, FamilienRolle::Elternteil, 'Talweg 5', '54321', 'Anderswo');
        $kind = $this->person(3, 'Lena', $f, FamilienRolle::Kind, 'am hang 1 ', '12345', 'beispielstadt');

        return [$f, $zahler, $eltern, $kind];
    }

    private function audit(string $aktion): int
    {
        $stmt = $this->dienste->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $stmt->execute([$aktion]);

        return (int) $stmt->fetchColumn();
    }

    private function htmx(string $pfad, array $query, array $post, string $ziel): Request
    {
        $session = new Session();
        $session->start();

        return new Request(
            HttpMethod::Post,
            $pfad,
            query: $query,
            post: [...$post, '_csrf' => $session->csrfToken()],
            headers: ['hx-request' => 'true', 'hx-target' => $ziel, 'x-csrf-token' => $session->csrfToken()],
        );
    }

    public function testTheListMarksFamiliesWhoseAddressesDiffer(): void
    {
        $this->als(Rolle::Vorstand);
        [$f] = $this->musterfamilie();
        $g = $this->familie('Familie Beispiel');
        $this->person(10, 'Anna', $g, FamilienRolle::Zahler);
        $this->person(11, 'Ben', $g, FamilienRolle::Kind, ' AM HANG 1', '12345', 'Beispielstadt');

        $zeilen = new FamilienListeRepository($this->dienste->pdo())->liste();
        self::assertSame(['Familie Beispiel', 'Familie Muster'], array_map(static fn($z): string => $z->name, $zeilen));
        self::assertFalse($zeilen[0]->anschriftenAbweichend, 'case and blanks do not count');
        self::assertTrue($zeilen[1]->anschriftenAbweichend);
        self::assertSame(3, $zeilen[1]->anzahl);
        self::assertSame(['Paul Muster'], $zeilen[1]->zahler);

        $seite = $this->get('/app/familien');
        self::assertSame(200, $seite->status);
        self::assertSame(1, substr_count($seite->body, 'Anschriften weichen ab'));
        self::assertStringContainsString('>Familie Muster</a>', $seite->body);
        self::assertStringContainsString('href="/app/familien?familie=' . $f . '"', $seite->body);
        self::assertStringContainsString('<a href="/app/familien" aria-current="page"', $seite->body, 'the main navigation has the entry');
    }

    public function testTheSearchFindsFamilyAndPersonNamesAndNeverPutsThemInTheUrl(): void
    {
        $this->als(Rolle::Vorstand);
        $this->musterfamilie();
        $this->familie('Familie Beispiel');

        self::assertSame(['Familie Muster'], array_map(static fn($z): string => $z->name, new FamilienListeRepository($this->dienste->pdo())->liste('lena')));

        $antwort = $this->post('/app/familien', ['text' => 'beispiel']);
        self::assertStringContainsString('>Familie Beispiel</a>', $antwort->body);
        self::assertStringNotContainsString('>Familie Muster</a>', $antwort->body);
        self::assertStringNotContainsString('beispiel&', $antwort->body);
        self::assertStringNotContainsString('text=', $antwort->body);

        // A query is ignored: the text only counts from the body.
        self::assertStringContainsString('>Familie Muster</a>', $this->get('/app/familien', ['text' => 'beispiel'])->body);
    }

    public function testTheOpenedFamilyShowsPersonsRolesAndAddresses(): void
    {
        $this->als(Rolle::Vorstand);
        [$f, $zahler, $eltern] = $this->musterfamilie();

        $seite = $this->get('/app/familien', ['familie' => (string) $f])->body;

        self::assertStringContainsString('class="geteilt geteilt-mit-detail"', $seite);
        self::assertStringContainsString('<h2 id="familie-detail-titel" tabindex="-1" autofocus>Familie Muster</h2>', $seite);
        self::assertStringContainsString('Talweg 5, 54321 Anderswo', $seite);
        self::assertSame(1, substr_count($seite, '>weicht ab<'), 'only Petra: Lena differs in case only');
        self::assertStringContainsString('Die Anschriften in dieser Familie weichen voneinander ab.', $seite);
        self::assertStringContainsString('href="/app/familien?familie=' . $f . '&amp;mitglied=' . $eltern->id . '"', $seite, 'a person opens on this page');
        self::assertStringContainsString('<option value="' . $zahler->id . '" selected>Paul Muster</option>', $seite, 'the payer is the new payer by default');
        self::assertStringContainsString('Anschrift übernehmen (<span data-anschrift-anzahl>1</span>)', $seite);
    }

    public function testAPersonOpensInPlaceOfTheFamilyWithTheWayBack(): void
    {
        $this->als(Rolle::Admin);
        [$f, , $eltern] = $this->musterfamilie();

        $seite = $this->get('/app/familien', ['familie' => (string) $f, 'mitglied' => (string) $eltern->id, 'reiter' => 'familie'])->body;

        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1" autofocus>Petra Muster</h2>', $seite);
        self::assertStringNotContainsString('id="familie-detail-titel"', $seite, 'the member stands in place of the family');
        self::assertStringContainsString('class="zurueck zurueck-immer" href="/app/familien?familie=' . $f . '"', $seite);
        self::assertStringContainsString('>Zurück zur Familie</a>', $seite);
        self::assertStringNotContainsString('detail-schliessen" href="/app/familien?familie=', $seite);
        self::assertStringContainsString('hx-post="/app/familien?familie=' . $f . '&amp;mitglied=' . $eltern->id . '&amp;reiter=verlauf"', $seite, 'tabs stay on this page');
        self::assertStringNotContainsString('In Familien öffnen', $seite, 'already there');
        self::assertDoesNotMatchRegularExpression('#href="/app/mitglieder(\?[^"]*)?"#', preg_replace('#<nav class="navi".*?</nav>#s', '', $seite) ?? '', 'nothing leads to the members page');

        // Back to the family: its heading takes the focus.
        $zurueck = $this->aufrufen($this->htmx('/app/familien', ['familie' => (string) $f], ['fokus' => 'zurueck'], 'familien-bereich'))->body;
        self::assertStringContainsString('<h2 id="familie-detail-titel" tabindex="-1" autofocus>', $zurueck);
        self::assertStringStartsWith('<div class="geteilt geteilt-mit-detail" id="familien-bereich">', trim($zurueck));
    }

    public function testSavingTheMemberReturnsToTheFamiliesPage(): void
    {
        $this->als(Rolle::Admin);
        [$f, , $eltern] = $this->musterfamilie();
        $formular = MitgliedFormular::aus($eltern);
        $zurueck = '/app/familien?familie=' . $f;

        $antwort = $this->post('/app/mitglieder/' . $eltern->id, [
            ...$formular->werte,
            'abteilungen' => array_map(strval(...), $formular->abteilungIds),
            'funktionen' => array_map(strval(...), $formular->funktionIds),
            'version' => (string) $eltern->version,
            'ort' => 'Neustadt',
            'zurueck' => $zurueck,
        ]);

        self::assertWeiterleitungNach($zurueck . '&mitglied=' . $eltern->id, $antwort);
    }

    public function testTheMembersTabLinksToTheFamiliesPage(): void
    {
        $this->als(Rolle::Vorstand);
        [$f, $zahler] = $this->musterfamilie();

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $zahler->id, 'reiter' => 'familie'])->body;

        self::assertStringContainsString('<a href="/app/familien?familie=' . $f . '">In Familien öffnen</a>', $seite);
    }

    public function testChangingTheRoleAndThePayer(): void
    {
        $this->als(Rolle::Vorstand);
        [$f, $zahler, $eltern, $kind] = $this->musterfamilie();

        self::assertWeiterleitungNach('/app/familien?familie=' . $f, $this->post("/app/familien/$f/rolle", ['mitglied_id' => (string) $kind->id, 'rolle' => 'elternteil']));
        self::assertSame(FamilienRolle::Elternteil, $this->repo()->finde((int) $kind->id)?->familieRolle);
        self::assertSame(2, $this->repo()->finde((int) $kind->id)?->version, 'a new version');

        self::assertWeiterleitungNach('/app/familien?familie=' . $f, $this->post("/app/familien/$f/zahler", ['mitglied_id' => (string) $eltern->id]));
        self::assertSame(FamilienRolle::Zahler, $this->repo()->finde((int) $eltern->id)?->familieRolle);
        self::assertSame(FamilienRolle::Elternteil, $this->repo()->finde((int) $zahler->id)?->familieRolle, 'the former payer becomes a parent');
        self::assertSame('geaendert', $this->repo()->versionen((int) $zahler->id)[0]->anlass->text);

        // A person of another family is not changed through this one.
        $g = $this->familie('Familie Beispiel');
        $fremd = $this->person(9, 'Fremd', $g, FamilienRolle::Kind);
        $this->post("/app/familien/$f/rolle", ['mitglied_id' => (string) $fremd->id, 'rolle' => 'zahler']);
        self::assertSame(FamilienRolle::Kind, $this->repo()->finde((int) $fremd->id)?->familieRolle);
    }

    public function testAddingAndRemovingPersons(): void
    {
        $this->als(Rolle::Vorstand);
        [$f, $zahler] = $this->musterfamilie();
        $g = $this->familie('Familie Beispiel');
        $einzeln = $this->person(20, 'Ella', $g, FamilienRolle::Zahler);
        $neu = $this->person(21, 'Max');

        // The search posts; the matches leave out the family's own persons.
        $treffer = $this->aufrufen($this->htmx('/app/familien', ['familie' => (string) $f], ['suche' => 'muster'], 'familie-treffer'))->body;
        self::assertStringContainsString('Ella Muster', $treffer);
        self::assertStringContainsString('jetzt in Familie Beispiel', $treffer);
        self::assertStringNotContainsString('Paul Muster', $treffer);

        $this->post("/app/familien/$f/hinzufuegen", ['mitglied_id' => (string) $neu->id, 'rolle' => 'kind']);
        self::assertSame($f, $this->repo()->finde((int) $neu->id)?->familieId);
        self::assertSame(FamilienRolle::Kind, $this->repo()->finde((int) $neu->id)?->familieRolle);

        // From another family: moves, the empty family is gone (a hint, no block).
        $this->post("/app/familien/$f/hinzufuegen", ['mitglied_id' => (string) $einzeln->id, 'rolle' => 'elternteil']);
        self::assertSame($f, $this->repo()->finde((int) $einzeln->id)?->familieId);
        self::assertNull(new FamilieRepository($this->dienste->pdo())->finde($g));

        $this->post("/app/familien/$f/entfernen", ['mitglied_id' => (string) $neu->id]);
        self::assertNull($this->repo()->finde((int) $neu->id)?->familieId);
        self::assertNull($this->repo()->finde((int) $neu->id)?->familieRolle);
        self::assertSame(3, $this->repo()->finde((int) $neu->id)?->version);
        self::assertNotNull($this->repo()->finde((int) $zahler->id)?->familieId);
    }

    public function testNewRenameAndDeleteOnlyAnEmptyFamily(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/familien/neu', ['name' => 'Familie Neu']);
        self::assertSame(302, $antwort->status);
        self::assertMatchesRegularExpression('#^/app/familien\?familie=(\d+)$#', $antwort->headers['Location'] ?? '');
        $id = (int) substr((string) $antwort->headers['Location'], strlen('/app/familien?familie='));
        self::assertStringContainsString('In dieser Familie ist noch niemand.', $this->get('/app/familien', ['familie' => (string) $id])->body);
        self::assertStringContainsString('Leere Familie löschen', $this->get('/app/familien', ['familie' => (string) $id])->body);

        $this->post("/app/familien/$id/umbenennen", ['name' => 'Familie Umbenannt']);
        self::assertSame('Familie Umbenannt', new FamilieRepository($this->dienste->pdo())->finde($id)?->name);

        $person = $this->person(30, 'Ida', $id, FamilienRolle::Zahler);
        $this->post("/app/familien/$id/loeschen");
        self::assertNotNull(new FamilieRepository($this->dienste->pdo())->finde($id), 'a family with persons stays');
        self::assertSame($id, $this->repo()->finde((int) $person->id)?->familieId);

        $this->post("/app/familien/$id/entfernen", ['mitglied_id' => (string) $person->id]);
        self::assertNull(new FamilieRepository($this->dienste->pdo())->finde($id), 'the last one out: gone');

        $leer = $this->familie('Familie Leer');
        self::assertWeiterleitungNach('/app/familien', $this->post("/app/familien/$leer/loeschen"));
        self::assertNull(new FamilieRepository($this->dienste->pdo())->finde($leer));
    }

    public function testUnifyingChangesOnlyTheTickedPersonsWithAVersionEachAndAnAuditEntry(): void
    {
        $this->als(Rolle::Vorstand);
        [$f, $zahler, $eltern, $kind] = $this->musterfamilie();
        $dritte = $this->person(4, 'Tom', $f, FamilienRolle::Kind, 'Weg 3', '99999', 'Fernort');

        $antwort = $this->post("/app/familien/$f/anschrift", [
            'quelle' => (string) $zahler->id,
            'mitglieder' => [(string) $eltern->id, (string) $kind->id, '999'],
        ]);
        self::assertWeiterleitungNach('/app/familien?familie=' . $f, $antwort);

        $petra = $this->repo()->finde((int) $eltern->id);
        self::assertSame(['Am Hang 1', '12345', 'Beispielstadt', 'D'], [$petra?->strasse, $petra?->plz, $petra?->ort, $petra?->land]);
        self::assertSame(2, $petra?->version, 'one new version');
        self::assertSame('geaendert', $this->repo()->versionen((int) $eltern->id)[0]->anlass->text);
        self::assertSame(1, $this->repo()->finde((int) $kind->id)?->version, 'Lena already had the address - no version');
        self::assertSame('Weg 3', $this->repo()->finde((int) $dritte->id)?->strasse, 'not ticked: unchanged');
        self::assertSame(1, $this->repo()->finde((int) $zahler->id)?->version, 'the source stays');

        $zeile = $this->dienste->pdo()->query("SELECT entity, entity_id, details FROM audit_log WHERE action = 'familie.anschrift_uebernommen'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($zeile);
        self::assertSame(['familie', (string) $f], [$zeile['entity'], (string) $zeile['entity_id']]);
        self::assertSame(['quelle' => $zahler->id, 'mitglieder' => [$eltern->id]], json_decode((string) $zeile['details'], true));
        self::assertStringNotContainsString('Hang', (string) $zeile['details'], 'ids only, no address');

        // Nothing changes: no audit entry.
        $this->post("/app/familien/$f/anschrift", ['quelle' => (string) $zahler->id, 'mitglieder' => [(string) $eltern->id]]);
        self::assertSame(1, $this->audit('familie.anschrift_uebernommen'));

        // A source outside the family changes nothing.
        $fremd = $this->person(40, 'Fremd', null, null, 'Fremdweg 1');
        $this->post("/app/familien/$f/anschrift", ['quelle' => (string) $fremd->id, 'mitglieder' => [(string) $dritte->id]]);
        self::assertSame('Weg 3', $this->repo()->finde((int) $dritte->id)?->strasse);
    }

    public function testAnotherSourceTicksThePersonsThatDifferFromIt(): void
    {
        $this->als(Rolle::Vorstand);
        [$f, $zahler, $eltern, $kind] = $this->musterfamilie();

        $teil = $this->aufrufen($this->htmx('/app/familien', ['familie' => (string) $f], ['quelle' => (string) $eltern->id], 'familie-anschrift'))->body;

        self::assertStringStartsWith('<section class="familie-teil familie-anschrift" id="familie-anschrift"', trim($teil));
        self::assertStringContainsString('<option value="' . $eltern->id . '" selected>', $teil);
        self::assertStringContainsString('Talweg 5, 54321 Anderswo', $teil);
        self::assertStringContainsString('name="mitglieder[]" value="' . $zahler->id . '" checked', $teil);
        self::assertStringContainsString('name="mitglieder[]" value="' . $kind->id . '" checked', $teil);
        self::assertStringNotContainsString('value="' . $eltern->id . '" checked', $teil, 'the source itself is no choice');
        self::assertStringContainsString('(<span data-anschrift-anzahl>2</span>)', $teil);
    }

    public function testWithoutTheRightToEditThePageOnlyShows(): void
    {
        [$f, $zahler, $eltern] = $this->musterfamilie();
        $this->als(Rolle::Lesend);

        $seite = $this->get('/app/familien', ['familie' => (string) $f]);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Paul Muster', $seite->body);
        self::assertStringContainsString('Die Anschriften in dieser Familie weichen voneinander ab.', $seite->body);
        foreach (['Rolle speichern', 'Zahler wechseln', 'Anschrift übernehmen', 'Person hinzufügen', 'Familie anlegen', 'Umbenennen'] as $knopf) {
            self::assertStringNotContainsString($knopf, $seite->body, $knopf);
        }

        foreach ([
            ['/app/familien/neu', ['name' => 'X']],
            ["/app/familien/$f/umbenennen", ['name' => 'X']],
            ["/app/familien/$f/loeschen", []],
            ["/app/familien/$f/hinzufuegen", ['mitglied_id' => (string) $eltern->id]],
            ["/app/familien/$f/rolle", ['mitglied_id' => (string) $zahler->id, 'rolle' => 'kind']],
            ["/app/familien/$f/zahler", ['mitglied_id' => (string) $eltern->id]],
            ["/app/familien/$f/entfernen", ['mitglied_id' => (string) $eltern->id]],
            ["/app/familien/$f/anschrift", ['quelle' => (string) $zahler->id, 'mitglieder' => [(string) $eltern->id]]],
        ] as [$pfad, $felder]) {
            self::assertSame(403, $this->post($pfad, $felder)->status, $pfad);
        }
        self::assertSame(1, $this->repo()->finde((int) $eltern->id)?->version, 'nothing changed');
        self::assertSame(FamilienRolle::Zahler, $this->repo()->finde((int) $zahler->id)?->familieRolle);
        self::assertSame(0, $this->audit('familie.anschrift_uebernommen'));
    }

    public function testAGoneFamilyIsAHintNotAnError(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/familien', ['familie' => '999']);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Diese Familie gibt es nicht mehr.', $seite->body);

        self::assertWeiterleitungNach('/app/familien', $this->post('/app/familien/999/rolle', ['mitglied_id' => '1', 'rolle' => 'kind']));
    }

    public function testTheBarrierefreiModeKeepsEverythingAsLinksAndFocusesTheHeading(): void
    {
        $this->als(Rolle::Vorstand, barrierefrei: true);
        [$f] = $this->musterfamilie();

        $seite = $this->get('/app/familien', ['familie' => (string) $f])->body;
        self::assertStringContainsString('modus-barrierefrei', $seite);
        self::assertStringContainsString('tabindex="-1" autofocus>Familie Muster<', $seite);

        $this->dienste->session->flash('Rolle geändert.');
        self::assertStringNotContainsString('autofocus', $this->get('/app/familien', ['familie' => (string) $f])->body, 'after a change the message keeps the focus at the top');
    }
}
