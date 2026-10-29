<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\DatenReiter;
use App\App\Mitglied\MitgliedErweiterungen;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\Mitglied;
use App\Domain\Permission;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\Response;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Service\Storage\BlobService;
use App\Tests\Support\AppTestCase;

/**
 * Create, change and delete a member through the whole application
 * (docs/spec/07-mitglieder.md sections 1, 7 and 9; issue #19): the routes,
 * the real login guard and CSRF check, the templates. Every name below is
 * made up.
 */
final class MitgliedBearbeitenTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    private function wert(string $sql): mixed
    {
        return $this->dienste->pdo()->query($sql)->fetchColumn();
    }

    private function vorhanden(int $nummer, string $nachname = 'Musterfrau', array $mehr = []): Mitglied
    {
        return $this->repo()->speichern(new Mitglied($nummer, $nachname, ...$mehr), VersionsAnlass::angelegt(), null)->mitglied;
    }

    /**
     * The form as the tab shows it for this member, sent back with changes.
     *
     * @param array<string, mixed> $aenderungen
     * @return array<string, mixed>
     */
    private function formularVon(Mitglied $m, array $aenderungen = []): array
    {
        $formular = \App\App\Mitglied\MitgliedFormular::aus($m);

        return [
            ...$formular->werte,
            'abteilungen' => array_map(strval(...), $formular->abteilungIds),
            'funktionen' => array_map(strval(...), $formular->funktionIds),
            'version' => (string) $m->version,
            ...$aenderungen,
        ];
    }

    private function flash(): ?string
    {
        return $this->dienste->session->pullFlash()?->text;
    }

    private function idAus(Response $antwort): int
    {
        self::assertSame(302, $antwort->status);
        self::assertMatchesRegularExpression('#^/app/mitglieder\?mitglied=(\d+)$#', $antwort->headers['Location'] ?? '');

        return (int) substr($antwort->headers['Location'], strlen('/app/mitglieder?mitglied='));
    }

    public function testTheNewFormSuggestsTheNextFreeNumber(): void
    {
        $this->vorhanden(41);
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder/neu');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('<h1>Neues Mitglied</h1>', $seite->body);
        self::assertMatchesRegularExpression('/<input type="text" id="m-mitgliedsnummer" name="mitgliedsnummer"[^>]* value="42"/', $seite->body);
        self::assertStringContainsString('action="/app/mitglieder/neu"', $seite->body);
        self::assertStringContainsString('Mitglied anlegen', $seite->body);
        self::assertStringNotContainsString('Mitglied löschen', $seite->body);
        foreach (['Fußball', 'Übungsleiter', 'Austritt', 'verstorben'] as $angebot) {
            self::assertStringContainsString($angebot, $seite->body, 'departments, functions and reasons from the seeds');
        }
    }

    public function testCreatingAMemberWritesTheFirstVersionAndOpensIt(): void
    {
        $konto = $this->als(Rolle::Vorstand);
        $fussball = (string) $this->wert("SELECT id FROM abteilung WHERE name = 'Fußball'");

        $antwort = $this->post('/app/mitglieder/neu', [
            'mitgliedsnummer' => '412', 'vorname' => 'Erika', 'nachname' => 'Musterfrau', 'abteilungen' => [$fussball],
            'iban' => 'de89 3704 0044 0532 0130 00', 'aktiv' => '1', 'zahlart' => 'lastschrift',
        ]);

        $id = $this->idAus($antwort);
        $mitglied = $this->repo()->finde($id);
        self::assertNotNull($mitglied);
        self::assertSame(412, $mitglied->mitgliedsnummer);
        self::assertSame([(int) $fussball], $mitglied->abteilungIds);
        self::assertSame('DE89370400440532013000', $mitglied->iban, 'normalised');
        $versionen = $this->repo()->versionen($id);
        self::assertCount(1, $versionen);
        self::assertSame('angelegt', $versionen[0]->anlass->text);
        self::assertSame($konto->id, $versionen[0]->geaendertVon);
        self::assertSame('Mitglied angelegt.', $this->flash());
    }

    public function testWithoutSurnameNothingIsSavedAndTheInputStays(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/app/mitglieder/neu', ['mitgliedsnummer' => '5', 'vorname' => 'Erika', 'nachname' => '']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Bitte den Nachnamen eintragen.', $antwort->body);
        self::assertStringContainsString('value="Erika"', $antwort->body);
        self::assertStringContainsString('id="m-nachname" name="nachname" aria-describedby="m-nachname-fehler" aria-invalid="true"', $antwort->body);
        self::assertSame(0, (int) $this->wert('SELECT COUNT(*) FROM mitglied'));
    }

    public function testATakenNumberIsRefusedWithTheNextFreeOne(): void
    {
        $this->vorhanden(412, 'Musterfrau', ['vorname' => 'Erika']);
        $this->vorhanden(500);
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/mitglieder/neu', ['mitgliedsnummer' => '412', 'nachname' => 'Mustermann']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Die Nummer 412 hat schon Erika Musterfrau. Die nächste freie Nummer ist 501.', $antwort->body);
        self::assertSame(2, (int) $this->wert('SELECT COUNT(*) FROM mitglied'));
    }

    public function testEveryChangeIsAVersionAndAnUnchangedSaveIsNone(): void
    {
        $m = $this->vorhanden(7, 'Muster');
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, ['ort' => 'Musterstadt']));
        self::assertSame($m->id, $this->idAus($antwort));
        self::assertSame('Mitglied gespeichert.', $this->flash());
        $geaendert = $this->repo()->finde((int) $m->id);
        self::assertSame('Musterstadt', $geaendert?->ort);
        self::assertSame(2, $geaendert?->version);
        self::assertSame('geaendert', $this->repo()->versionen((int) $m->id)[0]->anlass->text);

        $this->post('/app/mitglieder/' . $m->id, $this->formularVon($geaendert));
        self::assertSame('Nichts geändert – das Mitglied war schon so gespeichert.', $this->flash());
        self::assertCount(2, $this->repo()->versionen((int) $m->id));
    }

    public function testDepartmentsAndFunctionsAreTicksAndCanAllBeUnticked(): void
    {
        $abteilungen = array_map(intval(...), $this->dienste->pdo()->query('SELECT id FROM abteilung ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        $funktion = (int) $this->wert("SELECT id FROM funktion WHERE name = 'Übungsleiter'");
        $m = $this->vorhanden(8, 'Muster');
        $this->als(Rolle::Admin);

        $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, [
            'abteilungen' => array_map(strval(...), $abteilungen), 'funktionen' => [(string) $funktion], 'lizenznummer' => 'UL-77',
        ]));
        $m = $this->repo()->finde((int) $m->id);
        self::assertSame($abteilungen, $m?->abteilungIds);
        self::assertSame([$funktion], $m->funktionIds);
        self::assertSame('UL-77', $m->lizenznummer);

        // Unticked boxes send nothing at all.
        $post = $this->formularVon($m);
        unset($post['abteilungen'], $post['funktionen']);
        $this->post('/app/mitglieder/' . $m->id, $post);
        $m = $this->repo()->finde((int) $m->id);
        self::assertSame([], $m?->abteilungIds);
        self::assertSame([], $m->funktionIds);
    }

    public function testAWrongIbanIsSavedWithAHint(): void
    {
        $m = $this->vorhanden(9, 'Muster');
        $this->als(Rolle::Vorstand);

        $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, ['iban' => 'DE00 1234 5678 9012 3456 78']));

        self::assertSame('DE00123456789012345678', $this->repo()->finde((int) $m->id)?->iban);
        self::assertStringContainsString('Die IBAN ist ungültig', (string) $this->flash());
    }

    public function testTheFamilyIsKeptWhenTheFormIsSaved(): void
    {
        $familie = new FamilieRepository($this->dienste->pdo())->anlegen('Familie Muster');
        $m = $this->vorhanden(10, 'Muster', ['familieId' => $familie->id, 'familieRolle' => \App\Domain\FamilienRolle::Zahler]);
        $this->als(Rolle::Vorstand);

        $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, ['vorname' => 'Max']));

        $m = $this->repo()->finde((int) $m->id);
        self::assertSame('Max', $m?->vorname);
        self::assertSame($familie->id, $m->familieId);
        self::assertSame(\App\Domain\FamilienRolle::Zahler, $m->familieRolle);
    }

    public function testASaveOnAnOlderVersionDoesNotOverwriteAndASecondSaveWins(): void
    {
        $m = $this->vorhanden(11, 'Muster');
        $this->repo()->speichern($m->mit(['ort' => 'Anderswo']), VersionsAnlass::geaendert(), null);
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, ['plz' => '12345']));

        self::assertSame(409, $antwort->status);
        self::assertStringContainsString('Jemand anderes hat dieses Mitglied gespeichert', $antwort->body);
        self::assertStringContainsString('value="12345"', $antwort->body, 'the input stays');
        self::assertStringContainsString('name="version" value="2"', $antwort->body, 'the next save is on the current version');
        self::assertSame('Anderswo', $this->repo()->finde((int) $m->id)?->ort);

        $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, ['plz' => '12345', 'version' => '2']));
        $jetzt = $this->repo()->finde((int) $m->id);
        self::assertSame('12345', $jetzt?->plz);
        self::assertSame('', $jetzt->ort, 'the second save is the whole form');
        self::assertSame(3, $jetzt->version);
    }

    public function testChangingToATakenNumberIsRefused(): void
    {
        $this->vorhanden(1, 'Erste');
        $m = $this->vorhanden(2, 'Zweite');
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/mitglieder/' . $m->id, $this->formularVon($m, ['mitgliedsnummer' => '1']));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Die Nummer 1 hat schon Erste.', $antwort->body);
        self::assertSame(2, $this->repo()->finde((int) $m->id)?->mitgliedsnummer);
    }

    public function testAnUnknownMemberIsNotFound(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(404, $this->post('/app/mitglieder/999', ['mitgliedsnummer' => '1', 'nachname' => 'X'])->status);
        self::assertSame(404, $this->get('/app/mitglieder/999/loeschen')->status);
        self::assertSame(404, $this->post('/app/mitglieder/999/loeschen')->status);
    }

    public function testTheBoardCreatesAndEditsButCannotDelete(): void
    {
        $m = $this->vorhanden(12, 'Muster');
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->get('/app/mitglieder/' . $m->id . '/loeschen')->status);
        self::assertSame(403, $this->post('/app/mitglieder/' . $m->id . '/loeschen')->status);
        self::assertNotNull($this->repo()->finde((int) $m->id));
    }

    public function testWithoutLoginNothingIsSaved(): void
    {
        $antwort = $this->post('/app/mitglieder/neu', ['mitgliedsnummer' => '1', 'nachname' => 'Muster'], mitToken: false);

        self::assertNotSame(200, $antwort->status);
        self::assertSame(0, (int) $this->wert('SELECT COUNT(*) FROM mitglied'));
    }

    public function testWithoutCsrfTokenNothingIsSaved(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(403, $this->post('/app/mitglieder/neu', ['mitgliedsnummer' => '1', 'nachname' => 'Muster'], mitToken: false)->status);
        self::assertSame(0, (int) $this->wert('SELECT COUNT(*) FROM mitglied'));
    }

    public function testTheAdminDeletesAfterAClearQuestion(): void
    {
        $m = $this->vorhanden(13, 'Musterfrau', ['vorname' => 'Erika']);
        $this->repo()->speichern($m->mit(['ort' => 'Musterstadt']), VersionsAnlass::geaendert(), null);
        $blobs = BlobService::aus($this->dienste);
        $datei = $blobs->storeString('Rentenbescheid (erfunden)', new BlobMeta('application/pdf'), BlobStorage::Db);
        $this->dienste->pdo()->exec("INSERT INTO nachweis (mitglied_id, art, bemerkung, blob_id, vermerkt_am) VALUES ({$m->id}, 'rente', '', {$datei->id}, NOW())");
        $this->als(Rolle::Admin);

        $frage = $this->get('/app/mitglieder/' . $m->id . '/loeschen');
        self::assertSame(200, $frage->status);
        self::assertStringContainsString('Mitglied löschen?', $frage->body);
        self::assertStringContainsString('Erika Musterfrau', $frage->body);
        self::assertStringContainsString('lässt sich nicht rückgängig machen', $frage->body);
        self::assertStringContainsString('Austrittsdatum', $frage->body);
        self::assertStringContainsString('action="/app/mitglieder/' . $m->id . '/loeschen"', $frage->body);

        $antwort = $this->post('/app/mitglieder/' . $m->id . '/loeschen');

        self::assertWeiterleitungNach('/app/mitglieder', $antwort);
        self::assertSame('Mitglied Nr. 13 ist gelöscht.', $this->flash());
        self::assertNull($this->repo()->finde((int) $m->id));
        self::assertSame(0, (int) $this->wert('SELECT COUNT(*) FROM mitglied_version'));
        self::assertSame(0, (int) $this->wert('SELECT COUNT(*) FROM nachweis'));
        self::assertNull($blobs->find($datei->id), 'the file of the proof is gone too');
        $audit = $this->dienste->pdo()->query("SELECT entity, entity_id, details FROM audit_log WHERE action = 'mitglied.geloescht'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['entity' => 'mitglied', 'entity_id' => (string) $m->id, 'details' => '{"mitgliedsnummer":13}'], $audit);
    }

    public function testTheTabDatenIsTheFormOfTheOpenedMember(): void
    {
        $m = $this->vorhanden(14, 'Musterfrau', ['vorname' => 'Erika', 'iban' => 'DE00123456789012345678']);
        $konto = $this->als(Rolle::Vorstand);
        $this->dienste->view->setAnmeldung($konto, 'token-123');

        $reiter = MitgliedErweiterungen::reiter()->alle($this->dienste, $konto);
        self::assertInstanceOf(DatenReiter::class, $reiter[0] ?? null, 'the first tab, so a member opens on it');
        self::assertSame(Permission::MitgliederSehen, $reiter[0]->recht());

        $html = $this->dienste->view->fragment($reiter[0]->template(), $reiter[0]->daten((int) $m->id));
        self::assertStringContainsString('action="/app/mitglieder/' . $m->id . '"', $html);
        self::assertStringContainsString('name="_csrf" value="token-123"', $html);
        self::assertStringContainsString('name="version" value="1"', $html);
        self::assertStringContainsString('value="Erika"', $html);
        self::assertStringContainsString('Mitglied speichern', $html);
        self::assertStringContainsString('Die Prüfziffer passt nicht', $html);
        self::assertStringContainsString('Leer lassen: es gilt die Mitgliedsnummer (14).', $html);
        self::assertStringContainsString('Keine Familie.', $html);
        self::assertStringNotContainsString('Mitglied löschen', $html, 'the board does not delete');

        $admin = $this->als(Rolle::Admin);
        $this->dienste->view->setAnmeldung($admin, 'token-123');
        $html = $this->dienste->view->fragment($reiter[0]->template(), $reiter[0]->daten((int) $m->id));
        self::assertStringContainsString('href="/app/mitglieder/' . $m->id . '/loeschen"', $html);

        self::assertStringContainsString('gibt es nicht mehr', $this->dienste->view->fragment($reiter[0]->template(), $reiter[0]->daten(999)));
    }
}
