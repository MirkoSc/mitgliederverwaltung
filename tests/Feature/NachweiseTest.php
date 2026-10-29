<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\NachweiseAbschnitt;
use App\Domain\Mitglied;
use App\Domain\NachweisArt;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\MitgliedRepository;
use App\Repository\NachweisRepository;
use App\Service\Storage\BlobService;
use App\Tests\Support\AppTestCase;

/**
 * Proofs with an optional file through the whole application (docs/spec/
 * 07-mitglieder.md sections 5 and 9; issue #23). All data is made up.
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
        self::assertStringContainsString('attachment', $antwort->headers['Content-Disposition']);
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

        $daten = new NachweiseAbschnitt($this->dienste)->daten($id);

        self::assertCount(1, $daten['nachweise']);
        self::assertTrue($daten['darfPflegen']);
        self::assertFalse($daten['darfLoeschen']);
        $html = $this->dienste->view->fragment('mitglied/nachweise', $daten);
        self::assertStringContainsString('abgelaufen', $html);
        self::assertStringNotContainsString('Nachweis löschen', $html);
    }
}
