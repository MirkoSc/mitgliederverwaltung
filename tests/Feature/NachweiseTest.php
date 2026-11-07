<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\NachweiseReiter;
use App\Domain\Mitglied;
use App\Domain\NachweisArt;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisLader;
use App\Repository\MitgliedRepository;
use App\Repository\NachweisRepository;
use App\Service\Storage\BlobService;
use App\Tests\Support\AppTestCase;

/**
 * Proofs with an optional file through the whole application (docs/spec/
 * 07-mitglieder.md sections 5 and 9; issues #23 and #137). All data is
 * made up.
 */
final class NachweiseTest extends AppTestCase
{
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

    private function mitglied(int $nummer = 1): int
    {
        return (int) new MitgliedRepository($this->dienste->pdo())
            ->speichern(new Mitglied($nummer, 'Musterfrau'), VersionsAnlass::angelegt(), null)->mitglied->id;
    }

    private function repo(): NachweisRepository
    {
        return new NachweisRepository($this->dienste->pdo());
    }

    /**
     * @param array<string, string> $felder
     * @param string|null $inhalt file content; null = no file
     */
    private function anlegen(int $id, array $felder, ?string $inhalt = null, string $name = 'scan.pdf'): \App\Http\Response
    {
        $session = new Session();
        $session->start();
        $felder['_csrf'] = $session->csrfToken();
        $dateien = [];
        if ($inhalt !== null) {
            $pfad = tempnam(sys_get_temp_dir(), 'nw');
            self::assertIsString($pfad);
            $this->temp[] = $pfad;
            file_put_contents($pfad, $inhalt);
            $dateien = ['datei' => ['name' => $name, 'tmp_name' => $pfad, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)]];
        }

        return $this->aufrufen(new Request(HttpMethod::Post, "/app/mitglieder/$id/nachweise", post: $felder, files: $dateien));
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    public function testNotesAProofWithAFileAndSetsTheRecordFieldsItself(): void
    {
        $konto = $this->als(Rolle::Vorstand);
        $id = $this->mitglied();

        // The form fields vermerkt_von / vermerkt_am are ignored.
        $antwort = $this->anlegen($id, [
            'art' => 'rente', 'gueltig_bis' => '2030-06-30', 'bemerkung' => 'Rentenbescheid',
            'vermerkt_von' => '1', 'vermerkt_am' => '2000-01-01',
        ], $this->pdf());

        self::assertSame(302, $antwort->status);
        $liste = $this->repo()->fuerMitglied($id);
        self::assertCount(1, $liste);
        $n = $liste[0];
        self::assertSame(NachweisArt::Rente, $n->art);
        self::assertSame('2030-06-30', $n->gueltigBis?->format('Y-m-d'));
        self::assertSame('Rentenbescheid', $n->bemerkung);
        self::assertSame($konto->id, $n->vermerktVon);
        self::assertGreaterThan(new \DateTimeImmutable('2020-01-01'), $n->vermerktAm);
        self::assertNotNull($n->blobId);
    }

    public function testTheFileIsEncryptedAtRestAndDownloadsUnchanged(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'studium'], $this->pdf(), 'Bescheinigung.pdf');
        $n = $this->repo()->fuerMitglied($id)[0];

        $blobs = BlobService::aus($this->dienste);
        $blob = $blobs->find((int) $n->blobId);
        self::assertNotNull($blob);
        $cipher = implode('', iterator_to_array($blobs->readCipher($blob), false));
        self::assertStringNotContainsString('%PDF', $cipher);

        $antwort = $this->kernel->handle(new Request(HttpMethod::Get, "/app/mitglieder/$id/nachweise/{$n->id}/datei"));
        self::assertInstanceOf(StreamResponse::class, $antwort);
        self::assertSame('application/pdf', $antwort->headers['Content-Type']);
        self::assertSame('attachment; filename="nachweis-' . $n->id . '.pdf"', $antwort->headers['Content-Disposition'], 'no uploaded name');
        self::assertSame($this->pdf(), implode('', iterator_to_array($antwort->chunks, false)));
    }

    public function testAProofWithoutAFileIsFine(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();

        $this->anlegen($id, ['art' => 'sonstiges', 'bemerkung' => 'mündlich']);

        $n = $this->repo()->fuerMitglied($id)[0];
        self::assertNull($n->blobId);
        self::assertNull($n->gueltigBis);
    }

    public function testAWrongFileTypeOrArtIsRefusedAndNothingIsSaved(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();

        $this->anlegen($id, ['art' => 'rente'], '<?php echo 1;', 'x.pdf');
        $this->anlegen($id, ['art' => 'unbekannt']);
        $this->anlegen($id, ['art' => 'rente', 'gueltig_bis' => '31.12.2030']);

        self::assertSame([], $this->repo()->fuerMitglied($id));
        self::assertSame(0, (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn());
    }

    public function testOnlyTheAdminDeletesAndTheFileGoesWithTheProof(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'rente'], $this->pdf());
        $n = $this->repo()->fuerMitglied($id)[0];
        $pfad = "/app/mitglieder/$id/nachweise/{$n->id}/loeschen";

        $this->post($pfad);
        self::assertCount(1, $this->repo()->fuerMitglied($id), 'Vorstand must not delete');

        $this->als(Rolle::Admin);
        $this->post($pfad);
        self::assertSame([], $this->repo()->fuerMitglied($id));
        self::assertSame(0, (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn());
    }

    public function testAFileOfAnotherMemberCannotBeFetchedThroughThisOne(): void
    {
        $this->als(Rolle::Vorstand);
        $a = $this->mitglied(1);
        $b = $this->mitglied(2);
        $this->anlegen($a, ['art' => 'rente'], $this->pdf());
        $n = $this->repo()->fuerMitglied($a)[0];

        $antwort = $this->get("/app/mitglieder/$b/nachweise/{$n->id}/datei");

        self::assertSame(404, $antwort->status);
    }

    public function testTheSectionShowsExpiredProofsAndHidesDeleteFromTheVorstand(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'rente', 'gueltig_bis' => '2020-01-31', 'bemerkung' => 'alt']);

        $daten = new NachweiseReiter($this->dienste)->daten($id);

        self::assertCount(1, $daten['nachweise']);
        self::assertTrue($daten['darfPflegen']);
        self::assertFalse($daten['darfLoeschen']);
        $html = $this->dienste->view->fragment('mitglied/nachweise', $daten);
        self::assertStringContainsString('abgelaufen', $html);
        self::assertStringNotContainsString('Nachweis löschen', $html);
    }

    /**
     * Edit (#137): the proof $nachweisId through the route of member $id.
     *
     * @param array<string, string> $felder
     */
    private function bearbeiten(int $id, int $nachweisId, array $felder): \App\Http\Response
    {
        return $this->post("/app/mitglieder/$id/nachweise/$nachweisId/bearbeiten", $felder);
    }

    private function flash(): string
    {
        return (string) $this->dienste->session->pullFlash()?->text;
    }

    public function testEditingChangesExactlyKindEndDateAndRemark(): void
    {
        $konto = $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'studium', 'gueltig_bis' => '2027-09-30', 'bemerkung' => 'Immatrikulation'], $this->pdf());
        $vorher = $this->repo()->fuerMitglied($id)[0];
        $this->flash();

        // Another account changes it; the record fields and the file in the form are ignored.
        $this->als(Rolle::Admin);
        $antwort = $this->bearbeiten($id, (int) $vorher->id, [
            'art' => 'ausbildung', 'gueltig_bis' => '2028-08-31', 'bemerkung' => '  Ausbildungsvertrag  ',
            'vermerkt_von' => '1', 'vermerkt_am' => '2000-01-01', 'blob_id' => '999', 'mitglied_id' => '999',
        ]);

        self::assertSame(302, $antwort->status);
        self::assertSame('Nachweis gespeichert.', $this->flash());
        $nachher = $this->repo()->finde((int) $vorher->id);
        self::assertNotNull($nachher);
        self::assertSame(NachweisArt::Ausbildung, $nachher->art);
        self::assertSame('2028-08-31', $nachher->gueltigBis?->format('Y-m-d'));
        self::assertSame('Ausbildungsvertrag', $nachher->bemerkung);
        self::assertSame($id, $nachher->mitgliedId);
        self::assertSame($vorher->blobId, $nachher->blobId);
        self::assertSame($konto->id, $nachher->vermerktVon);
        self::assertEquals($vorher->vermerktAm, $nachher->vermerktAm);
    }

    public function testTheEndDateCanBeCleared(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'rente', 'gueltig_bis' => '2030-06-30', 'bemerkung' => 'Bescheid']);
        $n = $this->repo()->fuerMitglied($id)[0];

        $this->bearbeiten($id, (int) $n->id, ['art' => 'rente', 'gueltig_bis' => '', 'bemerkung' => 'Bescheid']);

        $nachher = $this->repo()->finde((int) $n->id);
        self::assertNull($nachher?->gueltigBis);
        self::assertSame('Bescheid', $nachher?->bemerkung);
    }

    public function testAWrongKindOrDateChangesNothingAndSaysSo(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'rente', 'gueltig_bis' => '2030-06-30', 'bemerkung' => 'Bescheid']);
        $n = $this->repo()->fuerMitglied($id)[0];
        $this->flash();

        $antwort = $this->bearbeiten($id, (int) $n->id, ['art' => 'unbekannt', 'gueltig_bis' => '2031-01-01', 'bemerkung' => 'neu']);
        self::assertSame(302, $antwort->status);
        self::assertSame('Bitte die Art des Nachweises wählen.', $this->flash());

        $this->bearbeiten($id, (int) $n->id, ['art' => 'studium', 'gueltig_bis' => '31.12.2031', 'bemerkung' => 'neu']);
        self::assertSame('Das Datum bei „gültig bis" ist ungültig.', $this->flash());

        $this->bearbeiten($id, (int) $n->id, ['art' => 'studium', 'gueltig_bis' => '2031-02-30', 'bemerkung' => 'neu']);
        self::assertSame('Das Datum bei „gültig bis" ist ungültig.', $this->flash());

        $nachher = $this->repo()->finde((int) $n->id);
        self::assertSame(NachweisArt::Rente, $nachher?->art);
        self::assertSame('2030-06-30', $nachher?->gueltigBis?->format('Y-m-d'));
        self::assertSame('Bescheid', $nachher?->bemerkung);
    }

    public function testAProofOfAnotherMemberIsNotFoundThroughThisOne(): void
    {
        $this->als(Rolle::Admin);
        $a = $this->mitglied(1);
        $b = $this->mitglied(2);
        $this->anlegen($a, ['art' => 'rente', 'bemerkung' => 'von A']);
        $n = $this->repo()->fuerMitglied($a)[0];

        self::assertSame(404, $this->bearbeiten($b, (int) $n->id, ['art' => 'sonstiges', 'bemerkung' => 'von B'])->status);
        self::assertSame(404, $this->bearbeiten($a, 999999, ['art' => 'sonstiges'])->status);

        $nachher = $this->repo()->finde((int) $n->id);
        self::assertSame($a, $nachher?->mitgliedId);
        self::assertSame(NachweisArt::Rente, $nachher?->art);
        self::assertSame('von A', $nachher?->bemerkung);
    }

    public function testReadOnlyGets403AndNoEditButton(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'rente', 'bemerkung' => 'Bescheid']);
        $n = $this->repo()->fuerMitglied($id)[0];
        $html = $this->dienste->view->fragment('mitglied/nachweise', new NachweiseReiter($this->dienste)->daten($id));
        self::assertStringContainsString('Nachweis bearbeiten', $html, 'the Vorstand may edit');
        self::assertStringContainsString('/nachweise/' . $n->id . '/bearbeiten', $html);

        $this->als(Rolle::Lesend);
        self::assertSame(403, $this->bearbeiten($id, (int) $n->id, ['art' => 'sonstiges'])->status);
        self::assertSame(NachweisArt::Rente, $this->repo()->finde((int) $n->id)?->art);

        $html = $this->dienste->view->fragment('mitglied/nachweise', new NachweiseReiter($this->dienste)->daten($id));
        self::assertStringNotContainsString('Nachweis bearbeiten', $html);
        self::assertStringNotContainsString('/bearbeiten', $html);
    }

    public function testTheExpiredHintGoesWhenTheEndDateMovesToTheFuture(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitglied();
        $this->anlegen($id, ['art' => 'sonstiges', 'gueltig_bis' => '2020-01-31']);
        $n = $this->repo()->fuerMitglied($id)[0];
        $abgelaufen = function () use ($id): bool {
            $mitglied = new MitgliedRepository($this->dienste->pdo())->finde($id);
            self::assertNotNull($mitglied);
            $arten = array_map(static fn($h): HinweisArt => $h->art, HinweisLader::aus($this->dienste->pdo())->fuer($mitglied));

            return in_array(HinweisArt::NachweisAbgelaufen, $arten, true);
        };
        self::assertTrue($abgelaufen());

        $this->bearbeiten($id, (int) $n->id, ['art' => 'sonstiges', 'gueltig_bis' => new \DateTimeImmutable('+1 year')->format('Y-m-d')]);

        self::assertFalse($abgelaufen());
    }
}
