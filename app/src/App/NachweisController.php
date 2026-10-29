<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\BlobMeta;
use App\Domain\Nachweis;
use App\Domain\NachweisArt;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\StreamResponse;
use App\Repository\MitgliedRepository;
use App\Repository\NachweisRepository;
use App\Service\Storage\BlobService;
use App\View\Area;
use App\View\FlashArt;

/**
 * Proofs of a member (docs/spec/07-mitglieder.md section 5, issue #23):
 * note one with an optional file, download the file, delete (admin only).
 * Who noted it and when are set here and in the repository, never taken
 * from the form. Answers with a redirect to the member and a message.
 *
 * The file comes with the form (multipart): a scan is small, and the form
 * then works without JavaScript.
 */
final readonly class NachweisController
{
    /** Largest file taken; above it the person is asked for a smaller scan. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** What is accepted as a file: content type (as detected) => shown name. */
    private const array ERLAUBT = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
    ];

    /** File name extension of the download, by type. */
    private const array ENDUNG = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private Dienste $dienste,
        private MitgliedRepository $mitglieder,
        private NachweisRepository $nachweise,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d,
            new MitgliedRepository($d->pdo()),
            new NachweisRepository($d->pdo()),
        ));
    }

    public static function adresse(int $mitgliedId, string $aktion = ''): string
    {
        return MitgliedController::PFAD_LISTE . '/' . $mitgliedId . '/nachweise' . ($aktion === '' ? '' : '/' . $aktion);
    }

    /** @param array<string, string> $params */
    public function anlegen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $id = (int) $mitglied->id;

        $art = NachweisArt::tryFrom($r->feld('art'));
        if ($art === null) {
            return $this->zurueck($id, 'Bitte die Art des Nachweises wählen.', FlashArt::Warnung);
        }
        $bis = null;
        if (trim($r->feld('gueltig_bis')) !== '') {
            $bis = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($r->feld('gueltig_bis')));
            if ($bis === false || $bis->format('Y-m-d') !== trim($r->feld('gueltig_bis'))) {
                return $this->zurueck($id, 'Das Datum bei „gültig bis" ist ungültig.', FlashArt::Warnung);
            }
        }

        $blobId = null;
        $datei = $r->files['datei'] ?? null;
        if (is_array($datei) && (int) ($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $fehler = $this->pruefeDatei($datei);
            if ($fehler !== null) {
                return $this->zurueck($id, $fehler, FlashArt::Warnung);
            }
            $blobId = $this->speichereDatei($datei);
            if ($blobId === null) {
                return $this->zurueck($id, 'Die Datei konnte nicht gespeichert werden. Bitte noch einmal versuchen.', FlashArt::Warnung);
            }
        }

        $this->nachweise->anlegen(new Nachweis(
            mitgliedId: $id,
            art: $art,
            gueltigBis: $bis,
            bemerkung: mb_substr(trim($r->feld('bemerkung')), 0, 500),
            blobId: $blobId,
            vermerktVon: $this->dienste->session->userId(),
        ));

        return $this->zurueck($id, 'Nachweis vermerkt.');
    }

    /** @param array<string, string> $params */
    public function datei(Request $r, array $params): ResponseInterface
    {
        $nachweis = $this->nachweise->finde((int) $params['nachweis']);
        if ($nachweis === null || $nachweis->mitgliedId !== (int) $params['id'] || $nachweis->blobId === null) {
            return $this->dateiFehlt();
        }
        $blobs = BlobService::aus($this->dienste);
        $blob = $blobs->find($nachweis->blobId);
        if ($blob === null || !$blob->isComplete()) {
            return $this->dateiFehlt();
        }
        // The session lock would block the person's other tabs for the whole download.
        $this->dienste->session->schliessen();

        // Not the uploaded name ("Attest_Erika_Mustermann.pdf"): no member
        // data in file names (CLAUDE.md section 4) - the id is enough.
        $name = 'nachweis-' . $nachweis->id . '.' . (self::ENDUNG[$blob->mimeType] ?? 'bin');

        return new StreamResponse($blobs->openRead($blob), [
            'Content-Type' => isset(self::ERLAUBT[$blob->mimeType]) ? $blob->mimeType : 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) $blob->size,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, string> $params */
    public function loeschen(Request $r, array $params): Response
    {
        $id = (int) $params['id'];
        $nachweis = $this->nachweise->finde((int) $params['nachweis']);
        if ($nachweis === null || $nachweis->mitgliedId !== $id) {
            return $this->zurueck($id, 'Diesen Nachweis gibt es nicht mehr.', FlashArt::Warnung);
        }

        $blobId = $this->nachweise->loeschen((int) $nachweis->id);
        if ($blobId !== null) {
            try {
                $blobs = BlobService::aus($this->dienste);
                $blob = $blobs->find($blobId);
                if ($blob !== null) {
                    $blobs->delete($blob);
                }
            } catch (\Throwable $fehler) {
                $this->dienste->logger->append(sprintf('Proof file %d could not be removed: %s', $blobId, $fehler::class));
            }
        }

        return $this->zurueck($id, 'Nachweis gelöscht.');
    }

    /**
     * @param array<string, mixed> $datei one entry of $_FILES
     */
    private function pruefeDatei(array $datei): ?string
    {
        $fehler = (int) ($datei['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
            return 'Die Datei ist zu groß. Bitte ein kleineres Bild oder PDF wählen.';
        }
        $pfad = (string) ($datei['tmp_name'] ?? '');
        if ($fehler !== UPLOAD_ERR_OK || $pfad === '' || !is_file($pfad)) {
            return 'Die Datei konnte nicht hochgeladen werden. Bitte noch einmal versuchen.';
        }
        if ((int) filesize($pfad) > self::MAX_BYTES) {
            return 'Die Datei ist zu groß (höchstens 10 MB).';
        }
        if (!isset(self::ERLAUBT[self::mime($pfad)])) {
            return 'Bitte ein Bild (JPEG, PNG, WebP) oder ein PDF hochladen.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $datei one entry of $_FILES, already checked
     */
    private function speichereDatei(array $datei): ?int
    {
        $pfad = (string) $datei['tmp_name'];
        $strom = fopen($pfad, 'rb');
        if ($strom === false) {
            return null;
        }
        try {
            return BlobService::aus($this->dienste)
                ->storeStream($strom, new BlobMeta(self::mime($pfad), BlobMeta::bereinigterName((string) ($datei['name'] ?? ''))))
                ->id;
        } catch (\Throwable $fehler) {
            $this->dienste->logger->append('Proof file could not be stored: ' . $fehler::class);

            return null;
        } finally {
            fclose($strom);
        }
    }

    /** The content type by looking at the bytes - the browser's word counts for nothing. */
    private static function mime(string $pfad): string
    {
        return (string) new \finfo(FILEINFO_MIME_TYPE)->file($pfad);
    }

    private function zurueck(int $mitgliedId, string $meldung, FlashArt $art = FlashArt::Ok): Response
    {
        $this->dienste->session->flash($meldung, $art);

        return Response::redirect(MitgliedController::adresse($mitgliedId, 'nachweise'));
    }

    private function gibtEsNicht(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Mitglied nicht gefunden',
            'meldung' => 'Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.',
            'startseite' => MitgliedController::PFAD_LISTE,
        ], Area::App), 404);
    }

    private function dateiFehlt(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Datei nicht gefunden',
            'meldung' => 'Zu diesem Nachweis gibt es keine Datei (mehr).',
            'startseite' => MitgliedController::PFAD_LISTE,
        ], Area::App), 404);
    }
}
