<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\FamilienRolle;
use App\Domain\Herkunft;
use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\FamilieRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Import\Abgleich;
use App\Service\Import\AbgleichVergleich;
use App\Service\Import\ErstImport;
use App\Service\Storage\BlobService;
use App\Tests\Support\AppTestCase;

/**
 * The reconciliation with a later file through the whole application
 * (docs/spec/10-import.md section 4; issue #34): the merge view sorts into
 * Neu / Fehlt in der Datei / Geändert, only ticked rows are taken over as a
 * step chain, each change is a version "abgleich", a second run with the
 * same file finds nothing but the members who left to be deleted; only
 * former members are deleted. The master data are the seeds of the migrations
 * (Fußball 09, Aktiv, Familie). All data is made up.
 */
final class AbgleichTest extends AppTestCase
{
    private const string KOPF = 'mitgliedsnummer;nachname;vorname;geburtsdatum;eintrittsdatum;ort;aktiv;abteilungen;beitragsart;familie;familie_name;familie_rolle';

    /** @var list<string> */
    private array $temp = [];

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $pfad) {
            @unlink($pfad);
        }
        parent::tearDown();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    private function abgleich(): Abgleich
    {
        return Abgleich::aus($this->dienste);
    }

    /** One line; $familie is "key;name;role". */
    private static function zeile(int $nr, string $ort = 'Altstadt', string $familie = ';;', string $beitrag = 'Aktiv'): string
    {
        return sprintf('%d;Name%d;Vorname%d;01.02.1990;01.01.2010;%s;1;09;%s;%s', $nr, $nr, $nr, $ort, $beitrag, $familie);
    }

    private static function csv(string ...$zeilen): string
    {
        return self::KOPF . "\n" . implode("\n", $zeilen) . "\n";
    }

    /** The first file: 1 pays for family F1 with child 2; 3 to 6 single. */
    private static function ersteDatei(): string
    {
        return self::csv(
            self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'),
            self::zeile(2, familie: 'F1;;kind', beitrag: ''),
            self::zeile(3),
            self::zeile(4),
            self::zeile(5),
            self::zeile(6),
        );
    }

    /**
     * The later file: 1 and 2 as before, 3 moved, 4 and 5 missing, 6 now a
     * child of the new family F7 whose payer 7 is new, 8 new and single.
     */
    private static function spaetereDatei(): string
    {
        return self::csv(
            self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'),
            self::zeile(2, familie: 'F1;;kind', beitrag: ''),
            self::zeile(3, 'Neustadt'),
            self::zeile(6, familie: 'F7;Familie Sieben;kind', beitrag: ''),
            self::zeile(7, familie: 'F7;;zahler', beitrag: 'Familie'),
            self::zeile(8),
        );
    }

    private function hochladen(string $csv): Response
    {
        $session = new Session();
        $session->start();
        $pfad = tempnam(sys_get_temp_dir(), 'abg');
        self::assertIsString($pfad);
        $this->temp[] = $pfad;
        file_put_contents($pfad, $csv);

        return $this->aufrufen(new Request(
            HttpMethod::Post,
            '/admin/import/hochladen',
            post: ['_csrf' => $session->csrfToken()],
            files: ['datei' => ['name' => 'import.csv', 'tmp_name' => $pfad, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]],
        ));
    }

    /** @return array<string, mixed> */
    private function json(Response $antwort): array
    {
        return json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** The first import of ersteDatei(), as the import itself does it. */
    private function erstimport(): void
    {
        self::assertSame(302, $this->hochladen(self::ersteDatei())->status);
        self::assertSame(200, $this->post('/admin/import/starten')->status);
        do {
            $stand = $this->json($this->post('/admin/import/schritt'));
        } while ($stand['fertig'] === false);
        self::assertNull(ErstImport::aus($this->dienste)->vorschau(), 'the first import is done');
    }

    /**
     * Starts with the given ticks and runs the chain to its end.
     *
     * @param array<string, string> $felder
     * @return array<string, mixed> the last answer
     */
    private function uebernehmen(array $felder): array
    {
        $start = $this->post('/admin/import/abgleich/starten', $felder);
        self::assertSame(200, $start->status, $start->body);
        $schritte = 0;
        do {
            $antwort = $this->post('/admin/import/abgleich/schritt');
            self::assertSame(200, $antwort->status, $antwort->body);
            $stand = $this->json($antwort);
            $schritte++;
        } while ($stand['fertig'] === false && $schritte < 100);

        return $stand;
    }

    private function vergleich(): AbgleichVergleich
    {
        $vergleich = $this->abgleich()->vorschau()?->vergleich;
        self::assertNotNull($vergleich);

        return $vergleich;
    }

    /** @param list<object> $zeilen */
    private static function nummern(array $zeilen): array
    {
        return array_map(static fn(object $z): int => ($z->mitglied ?? $z->vorher)->mitgliedsnummer, $zeilen);
    }

    private function anzahlZeilen(string $tabelle): int
    {
        return (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }

    /** Gives a member another number, as the member form does for the Vorstand. */
    private function umnummerieren(int $von, int $nach): void
    {
        $mitglied = $this->repo()->findeNachNummer($von);
        self::assertNotNull($mitglied);
        $this->repo()->speichern($mitglied->mit(['mitgliedsnummer' => $nach]), VersionsAnlass::geaendert(), null);
    }

    public function testAnUploadIntoAStockThatIsNotEmptyShowsTheMergeView(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        // Entered by hand, left already: neither may show as "missing".
        $this->repo()->speichern(new Mitglied(9001, 'Handgemacht'), VersionsAnlass::angelegt(), null);
        $fuenf = $this->repo()->findeNachNummer(5);
        self::assertNotNull($fuenf);
        $this->repo()->speichern($fuenf->mit(['austrittsdatum' => new \DateTimeImmutable('2026-03-31')]), VersionsAnlass::geaendert(), null);

        $antwort = $this->hochladen(self::spaetereDatei());

        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertNull(ErstImport::aus($this->dienste)->vorschau(), 'no first import is started');
        $vergleich = $this->vergleich();
        self::assertSame([7, 8], self::nummern($vergleich->neu));
        self::assertSame([4, 5], self::nummern($vergleich->fehlt), 'not 9001 (by hand)');
        self::assertSame([false, true], array_map(static fn($f): bool => $f->kannLoeschen, $vergleich->fehlt), '4 may only leave, 5 (left) only be deleted');
        self::assertSame([3, 6], self::nummern($vergleich->geaendert));
        self::assertSame(2, $vergleich->unveraendert);
        self::assertSame(['ort'], array_keys($vergleich->geaendert[0]->felder));
        self::assertSame(['beitragsart_id', 'familie_id', 'familie_rolle'], array_keys($vergleich->geaendert[1]->felder));

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Fehlt in der Datei', $seite);
        self::assertStringContainsString('neue Familie „Familie Sieben“', $seite);
        self::assertStringContainsString('<del>Altstadt</del>', $seite);
        self::assertStringContainsString('<ins>Neustadt</ins>', $seite);
        self::assertMatchesRegularExpression('/name="neu" value="7" checked/', $seite, 'new rows are ticked');
        self::assertDoesNotMatchRegularExpression('/name="aendern" value="3" checked/', $seite, 'changes are not');
        self::assertStringContainsString('/js/abgleich.js', $seite);
        self::assertStringNotContainsString('/js/import.js', $seite);
    }

    public function testOnlyTheTickedRowsAreTakenOverEachAsAVersionAbgleich(): void
    {
        $konto = $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());

        // 7 and 6 are not ticked; 4 leaves on the given day.
        $stand = $this->uebernehmen(['neu' => '8', 'aendern' => '3', 'austritt' => '4', 'austrittsdatum' => '2026-09-30']);

        self::assertTrue($stand['fertig']);
        self::assertSame(1, $stand['neu']);
        self::assertSame(1, $stand['geaendert']);
        self::assertSame(1, $stand['ausgetreten']);
        self::assertNull($this->repo()->findeNachNummer(7), 'not ticked, not created');
        self::assertNull($this->repo()->findeNachNummer(6)?->familieId, 'not ticked, not changed');
        self::assertNull(new FamilieRepository($this->dienste->pdo())->findeNachImportSchluessel('F7'));

        $acht = $this->repo()->findeNachNummer(8);
        self::assertNotNull($acht);
        self::assertSame(Herkunft::Import, $acht->herkunft, 'a new member counts as imported - it may go missing later');
        $versionen = $this->repo()->versionen((int) $acht->id);
        self::assertCount(1, $versionen);
        self::assertSame('abgleich', $versionen[0]->anlass->text);
        self::assertSame($konto->id, $versionen[0]->geaendertVon);

        $drei = $this->repo()->findeNachNummer(3);
        self::assertSame('Neustadt', $drei?->ort);
        $versionen = $this->repo()->versionen((int) $drei->id);
        self::assertSame([2, 1], array_map(static fn($v): int => $v->nr, $versionen));
        self::assertSame(['abgleich', 'importiert'], array_map(static fn($v): string => $v->anlass->text, $versionen));

        $vier = $this->repo()->findeNachNummer(4);
        self::assertSame('2026-09-30', $vier?->austrittsdatum?->format('Y-m-d'));
        self::assertSame('abgleich', $this->repo()->versionen((int) $vier->id)[0]->anlass->text);
        self::assertNotNull($this->repo()->findeNachNummer(1));
    }

    public function testFamiliesFollowTheFileAndASecondRunWithTheSameFileFindsNothing(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());

        $this->uebernehmen(['neu' => '7,8', 'aendern' => '3,6', 'austritt' => '4,5', 'austrittsdatum' => '2026-09-30']);

        $f7 = new FamilieRepository($this->dienste->pdo())->findeNachImportSchluessel('F7');
        self::assertNotNull($f7);
        self::assertSame('Familie Sieben', $f7->name);
        $personen = $this->repo()->derFamilie($f7->id);
        self::assertSame([7, 6], array_map(static fn(Mitglied $m): int => $m->mitgliedsnummer, $personen));
        self::assertSame([FamilienRolle::Zahler, FamilienRolle::Kind], array_map(static fn(Mitglied $m): ?FamilienRolle => $m->familieRolle, $personen));
        self::assertSame(1, (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM familie WHERE import_schluessel = 'F7'")->fetchColumn());

        $this->hochladen(self::spaetereDatei());

        $vergleich = $this->vergleich();
        self::assertSame([], $vergleich->neu, 'nothing new');
        self::assertSame([], $vergleich->geaendert, 'nothing changed');
        self::assertSame([4, 5], self::nummern($vergleich->fehlt), 'only the two who left, now former');
        self::assertSame([true, true], array_map(static fn($f): bool => $f->kannLoeschen, $vergleich->fehlt), 'to be deleted, not to leave again');
        self::assertSame(6, $vergleich->unveraendert);
    }

    public function testSendingNumbersOutsideTheirSectionChangesNothing(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());
        $vorher = $this->anzahlZeilen('mitglied_version');

        // 1 is unchanged, 3 is no new member, 8 is not missing.
        $start = $this->post('/admin/import/abgleich/starten', ['neu' => '3', 'aendern' => '1', 'austritt' => '8', 'austrittsdatum' => '2026-09-30']);

        self::assertSame(409, $start->status);
        self::assertStringContainsString('nichts angehakt', $this->json($start)['fehler']);
        self::assertSame($vorher, $this->anzahlZeilen('mitglied_version'));
    }

    /** Lets a member leave on a day that has passed: it is former now. */
    private function ausgetreten(int $nummer, string $datum = '2026-01-31'): Mitglied
    {
        $mitglied = $this->repo()->findeNachNummer($nummer);
        self::assertNotNull($mitglied);

        return $this->repo()->speichern($mitglied->mit(['austrittsdatum' => new \DateTimeImmutable($datum)]), VersionsAnlass::geaendert(), null)->mitglied;
    }

    public function testOnlyFormerMembersWithoutAnnualFeesCanBeDeletedTheOthersOnlyLeave(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $beitraege = new JahresbeitragRepository($this->dienste->pdo());
        $vier = $this->repo()->findeNachNummer(4);
        self::assertNotNull($vier);
        $beitraege->anlegenWennFehlt(new Jahresbeitrag((int) $vier->id, 2026, 8500));
        $this->ausgetreten(5);
        // Former, but with an annual fee: nothing to do here, not shown.
        $beitraege->anlegenWennFehlt(new Jahresbeitrag((int) $this->ausgetreten(6)->id, 2026, 8500));
        $this->hochladen(self::csv(self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'), self::zeile(2, familie: 'F1;;kind', beitrag: '')));

        $fehlt = $this->vergleich()->fehlt;
        self::assertSame([3, 4, 5], self::nummern($fehlt));
        self::assertSame([false, false, true], array_map(static fn($f): bool => $f->kannLoeschen, $fehlt));
        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('erst nach dem Austritt', $seite);
        self::assertStringContainsString('ausgetreten am 31.01.2026', $seite);
        self::assertMatchesRegularExpression('/name="loeschen" value="5"/', $seite);
        self::assertDoesNotMatchRegularExpression('/name="loeschen" value="[34]"/', $seite, 'no delete tick for a member who has not left');
        self::assertDoesNotMatchRegularExpression('/name="austritt" value="5"/', $seite, 'a former member does not leave again');

        // A forged POST deleting a member who has not left: refused, nothing done.
        $abgelehnt = $this->post('/admin/import/abgleich/starten', ['loeschen' => '3', 'austritt' => '4', 'austrittsdatum' => '2026-09-30']);
        self::assertSame(409, $abgelehnt->status);
        self::assertStringContainsString('Nr. 3 ist nicht ausgetreten', $this->json($abgelehnt)['fehler']);
        self::assertNotNull($this->repo()->findeNachNummer(3));
        self::assertNull($this->repo()->findeNachNummer(4)?->austrittsdatum, 'nothing was done');
        // Leaving again is no option for a former member.
        $nichts = $this->post('/admin/import/abgleich/starten', ['austritt' => '5', 'austrittsdatum' => '2026-09-30']);
        self::assertSame(409, $nichts->status);
        self::assertStringContainsString('nichts angehakt', $this->json($nichts)['fehler']);

        // Deleting needs no date; leaving does.
        $stand = $this->uebernehmen(['loeschen' => '5', 'austritt' => '3,4', 'austrittsdatum' => '2026-09-30']);

        self::assertSame(1, $stand['geloescht']);
        self::assertSame(2, $stand['ausgetreten']);
        self::assertNull($this->repo()->findeNachNummer(5));
        self::assertSame('2026-09-30', $this->repo()->findeNachNummer(3)?->austrittsdatum?->format('Y-m-d'));
        self::assertSame('2026-09-30', $this->repo()->findeNachNummer(4)?->austrittsdatum?->format('Y-m-d'));
        self::assertNotNull($this->repo()->findeNachNummer(6));
        $aktionen = array_map(static fn($e): string => $e->action, $this->dienste->audit()->neueste(5));
        self::assertContains('mitglied.geloescht', $aktionen);
    }

    public function testADeletionWhoseLeavingWasTakenBackSinceTheStartIsSkipped(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $fuenf = $this->ausgetreten(5);
        $this->hochladen(self::spaetereDatei());
        self::assertSame(200, $this->post('/admin/import/abgleich/starten', ['loeschen' => '5'])->status);

        // Before the step runs, the leaving is taken back.
        $this->repo()->speichern($fuenf->mit(['austrittsdatum' => null]), VersionsAnlass::geaendert(), null);
        do {
            $stand = $this->json($this->post('/admin/import/abgleich/schritt'));
        } while ($stand['fertig'] === false);

        self::assertSame(0, $stand['geloescht']);
        self::assertSame(1, $stand['uebersprungen']);
        self::assertNotNull($this->repo()->finde((int) $fuenf->id), 'a member again: stays');
        $aktionen = array_map(static fn($e): string => $e->action, $this->dienste->audit()->neueste(5));
        self::assertNotContains('mitglied.geloescht', $aktionen);
    }

    public function testLeavingNeedsAValidDate(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());

        $start = $this->post('/admin/import/abgleich/starten', ['austritt' => '4', 'austrittsdatum' => '31.02.2026']);

        self::assertSame(409, $start->status);
        self::assertStringContainsString('Austrittsdatum', $this->json($start)['fehler']);
    }

    public function testTheSummaryIsAFlashAndTheAuditLogHasOnlyNumbers(): void
    {
        $konto = $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());
        $blobId = (int) $this->abgleich()->aktuellerJob()?->state['blob_id'];
        self::assertGreaterThan(0, $blobId);

        $this->uebernehmen(['neu' => '7,8', 'aendern' => '3', 'austritt' => '4,5', 'austrittsdatum' => '2026-09-30']);

        self::assertNull(BlobService::aus($this->dienste)->find($blobId), 'the upload is gone');
        $eintrag = $this->dienste->audit()->neueste(1)[0];
        self::assertSame('abgleich.durchgefuehrt', $eintrag->action);
        self::assertSame($konto->id, $eintrag->userId);
        self::assertStringContainsString('"neu":2', $eintrag->details);
        self::assertStringContainsString('"ausgetreten":2', $eintrag->details);
        self::assertStringContainsString('"geaendert":1', $eintrag->details);
        self::assertStringNotContainsString('Name', $eintrag->details);

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Der Abgleich ist abgeschlossen: 2 neu, 2 ausgetreten, 1 geändert.', $seite);
        self::assertStringContainsString('Letzter Abgleich am', $seite);
        $job = $this->abgleich()->aktuellerJob();
        self::assertSame([], $job?->state['ops'], 'the record keeps no member numbers');
    }

    public function testARepeatedStepNeitherDuplicatesNorCountsTwice(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $zeilen = [self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'), self::zeile(2, familie: 'F1;;kind', beitrag: '')];
        for ($nr = 100; $nr < 250; $nr++) {
            $zeilen[] = self::zeile($nr, familie: 'F9;Familie Neun;kind', beitrag: '');
        }
        $this->hochladen(self::csv(...$zeilen));
        $neu = implode(',', range(100, 249));
        self::assertSame(200, $this->post('/admin/import/abgleich/starten', ['neu' => $neu, 'austritt' => '3,4', 'austrittsdatum' => '2026-09-30'])->status);
        $this->post('/admin/import/abgleich/schritt');
        self::assertSame(100, $this->anzahlZeilen('mitglied') - 6);

        // The first step died after its transaction, before its position was written.
        $job = $this->abgleich()->aktuellerJob();
        self::assertNotNull($job);
        $this->dienste->pdo()->prepare('UPDATE job SET state = ? WHERE id = ?')
            ->execute([json_encode([...$job->state, 'offset' => 0, 'neu' => 0]), $job->id]);

        do {
            $stand = $this->json($this->post('/admin/import/abgleich/schritt'));
        } while ($stand['fertig'] === false);

        self::assertSame(150, $stand['neu']);
        self::assertSame(2, $stand['ausgetreten']);
        self::assertSame(156, $this->anzahlZeilen('mitglied'), 'no member twice');
        self::assertSame(1, (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM familie WHERE import_schluessel = 'F9'")->fetchColumn());
    }

    public function testADeletionDoesNotFollowItsNumberToAnotherMember(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $drei = $this->ausgetreten(3);
        $vier = $this->ausgetreten(4);
        $this->hochladen(self::csv(self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'), self::zeile(2, familie: 'F1;;kind', beitrag: '')));
        self::assertSame(200, $this->post('/admin/import/abgleich/starten', ['loeschen' => '3'])->status);

        // Before the step runs, 3 gets another number and 4 gets number 3.
        $this->umnummerieren(3, 903);
        $this->umnummerieren(4, 3);
        do {
            $stand = $this->json($this->post('/admin/import/abgleich/schritt'));
        } while ($stand['fertig'] === false);

        self::assertSame(0, $stand['geloescht']);
        self::assertSame(1, $stand['uebersprungen']);
        self::assertSame(3, $this->repo()->finde((int) $vier->id)?->mitgliedsnummer, 'the member who holds 3 now stays');
        self::assertSame(903, $this->repo()->finde((int) $drei->id)?->mitgliedsnummer, 'renumbered since the start: left alone');
        $aktionen = array_map(static fn($e): string => $e->action, $this->dienste->audit()->neueste(5));
        self::assertNotContains('mitglied.geloescht', $aktionen);
    }

    public function testAChangeAndADepartureDoNotFollowTheirNumbersToOtherMembers(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $ids = [];
        foreach ([3, 4, 5, 6] as $nr) {
            $ids[$nr] = (int) $this->repo()->findeNachNummer($nr)?->id;
        }
        // 3 has moved, 4 is missing.
        $this->hochladen(self::csv(
            self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'),
            self::zeile(2, familie: 'F1;;kind', beitrag: ''),
            self::zeile(3, 'Neustadt'),
            self::zeile(5),
            self::zeile(6),
        ));
        self::assertSame(200, $this->post('/admin/import/abgleich/starten', ['aendern' => '3', 'austritt' => '4', 'austrittsdatum' => '2026-09-30'])->status);

        // Before the step runs, 5 takes the number 3 and 6 the number 4.
        $this->umnummerieren(3, 903);
        $this->umnummerieren(5, 3);
        $this->umnummerieren(4, 904);
        $this->umnummerieren(6, 4);
        $versionen = $this->anzahlZeilen('mitglied_version');
        do {
            $stand = $this->json($this->post('/admin/import/abgleich/schritt'));
        } while ($stand['fertig'] === false);

        self::assertSame(0, $stand['geaendert']);
        self::assertSame(0, $stand['ausgetreten']);
        self::assertSame(2, $stand['uebersprungen']);
        self::assertSame($versionen, $this->anzahlZeilen('mitglied_version'), 'nobody was changed');
        self::assertSame('Altstadt', $this->repo()->finde($ids[5])?->ort);
        self::assertNull($this->repo()->finde($ids[6])?->austrittsdatum);
        self::assertSame('Altstadt', $this->repo()->finde($ids[3])?->ort);
        self::assertNull($this->repo()->finde($ids[4])?->austrittsdatum);
    }

    public function testAChainStartedBeforeIdsWereStoredSkipsItsChangesDeparturesAndDeletions(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->ausgetreten(5);
        $this->hochladen(self::spaetereDatei());
        self::assertSame(200, $this->post('/admin/import/abgleich/starten', ['neu' => '8', 'aendern' => '3', 'austritt' => '4', 'loeschen' => '5', 'austrittsdatum' => '2026-09-30'])->status);

        // The state as the version before this one wrote it: (operation, number) only.
        $job = $this->abgleich()->aktuellerJob();
        self::assertNotNull($job);
        $ops = array_map(static fn(array $op): array => array_slice($op, 0, 2), $job->state['ops']);
        $this->dienste->pdo()->prepare('UPDATE job SET state = ? WHERE id = ?')
            ->execute([json_encode([...$job->state, 'ops' => $ops]), $job->id]);
        do {
            $stand = $this->json($this->post('/admin/import/abgleich/schritt'));
        } while ($stand['fertig'] === false);

        self::assertSame(1, $stand['neu']);
        self::assertSame(3, $stand['uebersprungen']);
        self::assertSame(0, $stand['geaendert'] + $stand['ausgetreten'] + $stand['geloescht']);
        self::assertNotNull($this->repo()->findeNachNummer(8));
        self::assertSame('Altstadt', $this->repo()->findeNachNummer(3)?->ort);
        self::assertNull($this->repo()->findeNachNummer(4)?->austrittsdatum);
        self::assertNotNull($this->repo()->findeNachNummer(5));
    }

    public function testACancelledChainKeepsWhatItDidAndIsRecordedInTheAuditLog(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $zeilen = [self::zeile(1, familie: 'F1;Familie Eins;zahler', beitrag: 'Familie'), self::zeile(2, familie: 'F1;;kind', beitrag: '')];
        for ($nr = 100; $nr < 250; $nr++) {
            $zeilen[] = self::zeile($nr);
        }
        $this->hochladen(self::csv(...$zeilen));
        $this->post('/admin/import/abgleich/starten', ['neu' => implode(',', range(100, 249))]);
        $this->post('/admin/import/abgleich/schritt');
        self::assertStringContainsString('Abgleich fortsetzen', $this->get('/admin')->body);

        $this->post('/admin/import/abgleich/verwerfen');

        self::assertNull($this->abgleich()->aktuellerJob());
        self::assertSame(106, $this->anzahlZeilen('mitglied'), 'the first step stays');
        $eintrag = $this->dienste->audit()->neueste(1)[0];
        self::assertSame('abgleich.durchgefuehrt', $eintrag->action);
        self::assertStringContainsString('"neu":100', $eintrag->details);
        self::assertStringContainsString('"abgebrochen":true', $eintrag->details);
    }

    public function testAFinishedChainAnswersAgainWithoutASecondSummary(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());
        $letzte = $this->uebernehmen(['neu' => '8']);
        self::assertTrue($letzte['abgeschlossen']);

        $nochmal = $this->json($this->post('/admin/import/abgleich/schritt'));

        self::assertTrue($nochmal['fertig']);
        self::assertArrayNotHasKey('abgeschlossen', $nochmal);
        self::assertSame(1, $nochmal['neu']);
    }

    public function testADiscardedReconciliationIsGoneWithItsFile(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());
        $blobId = (int) $this->abgleich()->aktuellerJob()?->state['blob_id'];

        $antwort = $this->post('/admin/import/abgleich/verwerfen');

        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertNull($this->abgleich()->aktuellerJob());
        self::assertNull(BlobService::aus($this->dienste)->find($blobId));
        self::assertStringContainsString('Datei hochladen und abgleichen', $this->get('/admin')->body);
    }

    public function testAFileWithErrorsShowsTheErrorsAndCannotBeTakenOver(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::csv(self::zeile(1), '1;Doppelt;Max;;;;1;99;Aktiv;;;'));

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Die Datei enthält Fehler', $seite);
        self::assertStringContainsString('BLSV-Nummer „99“', $seite);
        self::assertStringNotContainsString('id="abgleich-start"', $seite);
        self::assertSame(409, $this->post('/admin/import/abgleich/starten', ['neu' => '1'])->status);
    }

    public function testEmptyingTheStockAlsoDropsTheReconciliation(): void
    {
        $this->als(Rolle::Admin);
        $this->erstimport();
        $this->hochladen(self::spaetereDatei());
        $blobId = (int) $this->abgleich()->aktuellerJob()?->state['blob_id'];

        $this->post('/admin/import/leeren');

        self::assertNull($this->abgleich()->aktuellerJob());
        self::assertNull(BlobService::aus($this->dienste)->find($blobId));
    }

    public function testTheVorstandCannotReconcile(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/admin/import/abgleich/starten', ['neu' => '1'])->status);
        self::assertSame(403, $this->post('/admin/import/abgleich/schritt')->status);
        self::assertSame(403, $this->post('/admin/import/abgleich/verwerfen')->status);
    }
}
