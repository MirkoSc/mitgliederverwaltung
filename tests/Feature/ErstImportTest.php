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
use App\Repository\JobRepository;
use App\Repository\MitgliedRepository;
use App\Service\Import\Abgleich;
use App\Service\Import\ErstImport;
use App\Service\Storage\BlobService;
use App\Tests\Support\AppTestCase;


/**
 * The first import through the whole application (docs/spec/10-import.md
 * sections 1 and 2; issue #32): upload and preview, the step chain, version 1
 * with the reason "importiert", families by key, only into an empty stock,
 * and "Bestand leeren". The master data are the seeds of the migrations
 * (Fußball 09, Gymnastik 34, Aktiv, Familie, Übungsleiter). All data is made up.
 */
final class ErstImportTest extends AppTestCase
{
    private const string KOPF = 'mitgliedsnummer;nachname;vorname;geburtsdatum;eintrittsdatum;aktiv;abteilungen;beitragsart;zahlart;iban;familie;familie_name;familie_rolle;funktionen';

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

    private function import(): ErstImport
    {
        return ErstImport::aus($this->dienste);
    }

    /**
     * A valid file of $n members: every fifth a family of three (key F<i>).
     */
    private function csv(int $n): string
    {
        $zeilen = [self::KOPF];
        for ($i = 1; $i <= $n; $i++) {
            // Per block of five: member 1 pays for 2 and 3, 4 and 5 are single.
            $familie = match ($i % 5) {
                1 => 'F' . $i,
                2, 3 => 'F' . (5 * intdiv($i - 1, 5) + 1),
                default => null,
            };
            $rolle = $familie === null ? '' : ($i % 5 === 1 ? 'zahler' : 'kind');
            $zeilen[] = implode(';', [
                $i, 'Beispiel' . $i, 'Vorname' . $i, '01.02.1990', '01.01.2010', '1', '09,34',
                $rolle === 'zahler' ? 'Familie' : ($familie === null ? 'Aktiv' : ''), 'lastschrift',
                'DE02100208900014569054', $familie ?? '', $rolle === 'zahler' ? 'Familie Beispiel' . $i : '', $rolle,
                $i === 1 ? 'Übungsleiter' : '',
            ]);
        }

        return implode("\n", $zeilen) . "\n";
    }

    private function hochladen(string $csv): Response
    {
        $session = new Session();
        $session->start();
        $pfad = tempnam(sys_get_temp_dir(), 'imp');
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

    /** Uploads, starts and runs the chain to its end; returns the number of steps. */
    private function ganzImportieren(string $csv): int
    {
        self::assertSame(302, $this->hochladen($csv)->status);
        $start = $this->post('/admin/import/starten');
        self::assertSame(200, $start->status, $start->body);
        $schritte = 0;
        do {
            $antwort = $this->post('/admin/import/schritt');
            self::assertSame(200, $antwort->status, $antwort->body);
            $stand = $this->json($antwort);
            $schritte++;
        } while ($stand['fertig'] === false && $schritte < 100);

        return $schritte;
    }

    private function anzahlZeilen(string $tabelle): int
    {
        return (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }

    public function testTheUploadShowsAPreviewAndImportsNothing(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->hochladen($this->csv(12));

        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertSame(0, $this->anzahlZeilen('mitglied'));
        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Die Datei ist in Ordnung', $seite);
        self::assertStringContainsString('12 Mitglieder importieren', $seite);
        self::assertStringContainsString('Fußball: 12', $seite);
        self::assertStringContainsString('Gymnastik: 12', $seite);
        self::assertStringContainsString('/js/import.js', $seite);
    }

    public function testThePreviewCountsFamiliesAndShowsHintsButNotMissingProofs(): void
    {
        $this->als(Rolle::Admin);
        // F1 has three persons, so no "Familie zu klein"; member 6 starts a family of one.
        $this->hochladen($this->csv(6));

        $vorschau = $this->import()->vorschau(new \DateTimeImmutable('2026-06-01'));

        self::assertNotNull($vorschau);
        self::assertTrue($vorschau->istFehlerfrei());
        self::assertSame(6, $vorschau->mitglieder);
        self::assertSame(2, $vorschau->familien);
        self::assertArrayHasKey('Familie zu klein', $vorschau->hinweiseNachArt);
        self::assertArrayNotHasKey('Rentennachweis fehlt', $vorschau->hinweiseNachArt);
        self::assertSame(6, $vorschau->abteilungen['Fußball']);
    }

    public function testAFileWithErrorsIsPreviewedWithTheErrorsAndNothingCanBeStarted(): void
    {
        $this->als(Rolle::Admin);
        $csv = self::KOPF . "\n1;Gut;Erika;;;1;09;Aktiv;;;;;;\n1;Doppelt;Max;;;1;99;Gönner;;;;;;\n";
        $this->hochladen($csv);

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Die Datei enthält Fehler', $seite);
        self::assertStringContainsString('mehrfach', $seite);
        self::assertStringContainsString('BLSV-Nummer „99“', $seite);
        self::assertStringContainsString('Beitragsart „Gönner“', $seite);
        self::assertStringNotContainsString('id="import-start"', $seite);

        $start = $this->post('/admin/import/starten');
        self::assertSame(409, $start->status);
        self::assertSame(0, $this->anzahlZeilen('mitglied'), 'not even the valid-looking line is imported');
    }

    public function testTheImportRunsInStepsAndGivesEveryMemberVersionOneWithReasonImportiert(): void
    {
        $konto = $this->als(Rolle::Admin);

        $schritte = $this->ganzImportieren($this->csv(250));

        self::assertSame(3, $schritte, '250 lines in steps of 100');
        self::assertSame(250, $this->anzahlZeilen('mitglied'));
        self::assertSame(250, $this->anzahlZeilen('mitglied_version'));
        $erstes = $this->repo()->findeNachNummer(1);
        self::assertNotNull($erstes);
        self::assertSame(Herkunft::Import, $erstes->herkunft);
        self::assertSame(1, $erstes->version);
        $versionen = $this->repo()->versionen((int) $erstes->id);
        self::assertCount(1, $versionen);
        self::assertSame(1, $versionen[0]->nr);
        self::assertSame('importiert', $versionen[0]->anlass->text);
        self::assertSame($konto->id, $versionen[0]->geaendertVon);
        self::assertSame(
            0,
            (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM mitglied_version WHERE nr <> 1 OR anlass <> 'importiert'")->fetchColumn(),
        );
        self::assertCount(2, $erstes->abteilungIds);
        self::assertCount(1, $erstes->funktionIds);
    }

    public function testFamiliesAreCreatedByKeyWithRolesAndTheirName(): void
    {
        $this->als(Rolle::Admin);

        $this->ganzImportieren($this->csv(10));

        // Keys F1 (members 1-3) and F6 (members 6-8).
        self::assertSame(2, $this->anzahlZeilen('familie'));
        $familien = new FamilieRepository($this->dienste->pdo());
        $f1 = $familien->findeNachImportSchluessel('F1');
        self::assertNotNull($f1);
        self::assertSame('Familie Beispiel1', $f1->name);
        $personen = $this->repo()->derFamilie($f1->id);
        self::assertSame([1, 2, 3], array_map(static fn(Mitglied $m): int => $m->mitgliedsnummer, $personen));
        self::assertSame(
            [FamilienRolle::Zahler, FamilienRolle::Kind, FamilienRolle::Kind],
            array_map(static fn(Mitglied $m): ?FamilienRolle => $m->familieRolle, $personen),
        );
        self::assertNull($this->repo()->findeNachNummer(4)?->familieId);
    }

    public function testNumericFamilyKeysGiveTheFamiliesTheirOwnNamesAndCount(): void
    {
        $this->als(Rolle::Admin);
        $zeile = static fn(int $nr, string $familie, string $name, string $rolle): string => implode(';', [
            $nr, 'Beispiel' . $nr, 'Vorname' . $nr, '01.02.1990', '01.01.2010', '1', '09',
            $rolle === 'zahler' ? 'Familie' : '', 'lastschrift', 'DE02100208900014569054', $familie, $name, $rolle, '',
        ]);
        $csv = implode("\n", [
            self::KOPF,
            $zeile(1, '501', 'Familie Familie', 'zahler'),
            $zeile(2, '501', '', 'kind'),
            $zeile(3, '502', 'Familie Gemeinsam', 'zahler'),
            $zeile(4, '502', '', 'kind'),
        ]) . "\n";

        $this->hochladen($csv);
        $this->post('/admin/import/starten');
        $stand = $this->json($this->post('/admin/import/schritt'));

        self::assertTrue($stand['fertig']);
        self::assertSame(2, $stand['familien']);
        self::assertSame(2, $this->anzahlZeilen('familie'));
        $familien = new FamilieRepository($this->dienste->pdo());
        self::assertSame('Familie Familie', $familien->findeNachImportSchluessel('501')?->name);
        self::assertSame('Familie Gemeinsam', $familien->findeNachImportSchluessel('502')?->name);
    }

    public function testMembersEnteredByHandDuringTheImportAreNotCountedAsImported(): void
    {
        $this->als(Rolle::Admin);
        $this->hochladen($this->csv(150));
        $this->post('/admin/import/starten');
        $this->json($this->post('/admin/import/schritt'));

        // Somebody adds a member by hand between the steps.
        $this->repo()->speichern(
            new Mitglied(mitgliedsnummer: 9001, nachname: 'Handgemacht', vorname: 'Hans'),
            VersionsAnlass::angelegt(),
            null,
        );

        $stand = $this->json($this->post('/admin/import/schritt'));
        self::assertTrue($stand['fertig']);
        self::assertSame(150, $stand['angelegt']);
        self::assertSame(151, $this->anzahlZeilen('mitglied'));
    }

    public function testARepeatedStepNeitherDuplicatesNorFails(): void
    {
        $this->als(Rolle::Admin);
        $this->hochladen($this->csv(250));
        $this->post('/admin/import/starten');
        $this->post('/admin/import/schritt');
        self::assertSame(100, $this->anzahlZeilen('mitglied'));

        // The first step died after its transaction, before its position was
        // written: the next request does the same lines again.
        $job = $this->import()->aktuellerJob();
        self::assertNotNull($job);
        $this->dienste->pdo()->prepare('UPDATE job SET state = ? WHERE id = ?')
            ->execute([json_encode([...$job->state, 'offset' => 0]), $job->id]);

        $wiederholt = $this->json($this->post('/admin/import/schritt'));
        self::assertSame(100, $wiederholt['offset']);
        self::assertSame(100, $this->anzahlZeilen('mitglied'), 'no member twice');
        self::assertSame(0, $wiederholt['uebersprungen'], 'the lines done by the first try are not "skipped"');

        do {
            $stand = $this->json($this->post('/admin/import/schritt'));
        } while ($stand['fertig'] === false);
        self::assertSame(250, $this->anzahlZeilen('mitglied'));
        self::assertSame(250, $this->anzahlZeilen('mitglied_version'), 'version 1 only, once');
    }

    public function testAnAbandonedStepLockIsTakenOverAfterItExpires(): void
    {
        $this->als(Rolle::Admin);
        $this->hochladen($this->csv(5));
        $this->post('/admin/import/starten');
        $job = $this->import()->aktuellerJob();
        self::assertNotNull($job);

        // Another request holds the step: this one is told to wait, nothing is written.
        $sperre = new JobRepository($this->dienste->pdo())->sperren($job->id, 60);
        self::assertNotNull($sperre);
        self::assertSame(409, $this->post('/admin/import/schritt')->status);
        self::assertSame(0, $this->anzahlZeilen('mitglied'));

        // Its lock ran out (the request died): the next step goes through.
        $this->dienste->pdo()->exec("UPDATE job SET locked_until = '2000-01-01 00:00:00'");
        self::assertTrue($this->json($this->post('/admin/import/schritt'))['fertig']);
        self::assertSame(5, $this->anzahlZeilen('mitglied'));
    }

    public function testTheUploadIsDeletedAfterwardsAndTheImportIsRecordedInTheAuditLog(): void
    {
        $konto = $this->als(Rolle::Admin);
        $this->hochladen($this->csv(7));
        $blobId = (int) $this->import()->aktuellerJob()?->state['blob_id'];
        $blobs = BlobService::aus($this->dienste);
        self::assertNotNull($blobs->find($blobId));
        $inhalt = implode('', iterator_to_array($blobs->readCipher($blobs->find($blobId)), false));
        self::assertStringNotContainsString('Beispiel1', $inhalt, 'the upload is encrypted at rest');

        $this->post('/admin/import/starten');
        do {
            $stand = $this->json($this->post('/admin/import/schritt'));
        } while ($stand['fertig'] === false);

        self::assertNull($blobs->find($blobId), 'no copy of the member data stays behind');
        $eintraege = $this->dienste->audit()->neueste(5);
        self::assertSame('import.durchgefuehrt', $eintraege[0]->action);
        self::assertSame($konto->id, $eintraege[0]->userId);
        self::assertStringContainsString('"mitglieder":7', $eintraege[0]->details);
        self::assertStringNotContainsString('Beispiel', $eintraege[0]->details);

        // The record of the last import shows on the page.
        self::assertStringContainsString('Zuletzt importiert', $this->get('/admin')->body);
    }

    public function testNothingIsImportedIntoAStockThatIsNotEmpty(): void
    {
        $this->als(Rolle::Admin);
        $this->repo()->speichern(new Mitglied(1, 'Vorhanden'), VersionsAnlass::angelegt(), null);
        self::assertStringContainsString('nicht leer', $this->get('/admin')->body);

        $antwort = $this->hochladen($this->csv(3));

        // The file goes to the reconciliation (10 section 4) instead.
        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertNull($this->import()->aktuellerJob(), 'no first import is started');
        self::assertNotNull(Abgleich::aus($this->dienste)->aktuellerJob());
        self::assertSame(1, $this->anzahlZeilen('mitglied'));
        self::assertSame(409, $this->post('/admin/import/starten')->status);
    }

    public function testAMemberEnteredAfterTheUploadStopsTheStart(): void
    {
        $this->als(Rolle::Admin);
        $this->hochladen($this->csv(3));
        $this->repo()->speichern(new Mitglied(99, 'Dazwischen'), VersionsAnlass::angelegt(), null);

        $start = $this->post('/admin/import/starten');

        self::assertSame(409, $start->status);
        self::assertStringContainsString('nicht leer', $this->json($start)['fehler']);
        self::assertSame(1, $this->anzahlZeilen('mitglied'));
    }

    public function testADiscardedUploadIsGone(): void
    {
        $this->als(Rolle::Admin);
        $this->hochladen($this->csv(3));
        $blobId = (int) $this->import()->aktuellerJob()?->state['blob_id'];

        $antwort = $this->post('/admin/import/verwerfen');

        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertNull($this->import()->aktuellerJob());
        self::assertNull(BlobService::aus($this->dienste)->find($blobId));
    }

    public function testAnUploadWithoutAFileIsRefusedWithAMessage(): void
    {
        $this->als(Rolle::Admin);
        $session = new Session();
        $session->start();

        $antwort = $this->aufrufen(new Request(HttpMethod::Post, '/admin/import/hochladen', post: ['_csrf' => $session->csrfToken()]));

        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertNull($this->import()->aktuellerJob());
        self::assertStringContainsString('auswählen', $this->get('/admin')->body);
    }

    public function testEmptyingTheStockAsksFirstThenDeletesEverythingAndLetsTheImportRunAgain(): void
    {
        $this->als(Rolle::Admin);
        $this->ganzImportieren($this->csv(8));
        self::assertSame(8, $this->anzahlZeilen('mitglied'));

        $frage = $this->get('/admin/import/leeren');
        self::assertSame(200, $frage->status);
        self::assertStringContainsString('Alle 8 Mitglieder werden endgültig gelöscht', $frage->body);
        self::assertSame(8, $this->anzahlZeilen('mitglied'), 'asking changes nothing');

        $antwort = $this->post('/admin/import/leeren');

        self::assertWeiterleitungNach('/admin#import', $antwort);
        foreach (['mitglied', 'mitglied_version', 'mitglied_abteilung', 'mitglied_funktion', 'familie'] as $tabelle) {
            self::assertSame(0, $this->anzahlZeilen($tabelle), $tabelle);
        }
        self::assertNull($this->import()->aktuellerJob(), 'the record of the dry run is gone too');
        self::assertSame('bestand.geleert', $this->dienste->audit()->neueste(1)[0]->action);

        // The dry run can be repeated.
        $this->ganzImportieren($this->csv(8));
        self::assertSame(8, $this->anzahlZeilen('mitglied'));
    }

    public function testTheStockCannotBeEmptiedOnceAnAnnualFeeExists(): void
    {
        $this->als(Rolle::Admin);
        $this->ganzImportieren($this->csv(3));
        $id = (int) $this->repo()->findeNachNummer(1)?->id;
        new JahresbeitragRepository($this->dienste->pdo())->anlegenWennFehlt(new Jahresbeitrag($id, 2026, 8500));

        $frage = $this->get('/admin/import/leeren');
        self::assertWeiterleitungNach('/admin#import', $frage);
        $antwort = $this->post('/admin/import/leeren');

        self::assertWeiterleitungNach('/admin#import', $antwort);
        self::assertSame(3, $this->anzahlZeilen('mitglied'));
        self::assertStringContainsString('Jahresbeiträge', $this->get('/admin')->body);
        self::assertStringNotContainsString('Bestand leeren …', $this->get('/admin')->body);
    }

    public function testOnlyTheAdminReachesTheImport(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->get('/admin/import/leeren')->status);
        self::assertSame(403, $this->post('/admin/import/starten')->status);
    }

    public function testThePostRoutesNeedTheCsrfToken(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(403, $this->post('/admin/import/leeren', mitToken: false)->status);
    }

    public function testTheOutputOfTheConversionScriptImportsWithoutErrors(): void
    {
        $this->als(Rolle::Admin);
        // Expected output of tools/altdaten-umwandeln.ps1 (the CI job
        // "altdaten" keeps it byte-identical to what the script writes).
        $csv = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/altdaten/erwartet-import.csv');

        $this->ganzImportieren($csv);

        self::assertSame(27, $this->anzahlZeilen('mitglied'));
        self::assertGreaterThan(0, $this->anzahlZeilen('familie'));
    }
}
