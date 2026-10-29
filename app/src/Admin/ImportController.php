<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Service\Import\ErstImport;
use App\Service\Import\ImportLaeuftBereits;
use App\Service\Import\ImportNichtMoeglich;
use App\View\Area;
use App\View\FlashArt;

/**
 * The routes of the settings section Import (docs/spec/10-import.md
 * section 1): upload import.csv, start the chain and drive it one step per
 * request (public/js/import.js), drop the upload, empty the stock.
 *
 * Forms answer with a redirect back to the section and a flash message
 * (post/redirect/get); the two chain routes answer JSON.
 */
final readonly class ImportController
{
    public const string ZIEL = '/admin#import';

    public const string PFAD_LEEREN = '/admin/import/leeren';

    public function __construct(
        private Dienste $dienste,
        private ErstImport $import,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, ErstImport::aus($dienste));
    }

    public function hochladen(Request $request): Response
    {
        $datei = $request->files['datei'] ?? null;
        $fehler = $this->pruefeDatei(is_array($datei) ? $datei : null);
        if ($fehler !== null) {
            return $this->zurueck($fehler, FlashArt::Fehler);
        }

        try {
            $inhalt = (string) file_get_contents((string) $datei['tmp_name']);
            $this->import->hochladen($inhalt, (string) ($datei['name'] ?? ''), $this->dienste->session->userId());
        } catch (ImportNichtMoeglich $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }

        $this->dienste->session->flash('Die Datei ist hochgeladen. Unten steht die Vorschau – importiert ist noch nichts.');

        return Response::redirect(self::ZIEL);
    }

    /** Starts the chain; the page then asks for one step after the other. */
    public function starten(): ResponseInterface
    {
        try {
            $this->import->starten();
        } catch (ImportNichtMoeglich | ImportLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json(['phase' => 'import', 'fertig' => false, 'offset' => 0, 'gesamt' => 0]);
    }

    /** One step of the chain (CLAUDE.md section 1: no long request). */
    public function schritt(Request $request): ResponseInterface
    {
        try {
            $stand = $this->import->schritt($request->ip);
        } catch (ImportNichtMoeglich | ImportLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            // Only the class goes to the log; a database message may quote
            // row data (CLAUDE.md section 4).
            $this->dienste->logger->append('Import step failed: ' . $e::class);

            return Response::json(['fehler' => 'Der Schritt ist fehlgeschlagen. Bitte noch einmal versuchen – bereits importierte Zeilen werden nicht doppelt angelegt.'], 500);
        }

        if ($stand['fertig'] === true) {
            $this->dienste->session->flash(sprintf(
                'Der Import ist abgeschlossen: %d Mitglieder in %d Familien%s. Bitte als Nächstes die Liste „Auffälligkeiten“ prüfen.',
                $stand['angelegt'],
                $stand['familien'],
                $stand['uebersprungen'] > 0 ? sprintf(', %d Zeilen übersprungen, weil die Mitgliedsnummer schon vergeben war', $stand['uebersprungen']) : '',
            ));
        }

        return Response::json($stand);
    }

    public function verwerfen(): Response
    {
        $this->import->verwerfen();
        $this->dienste->session->flash('Der Import ist abgebrochen und die hochgeladene Datei gelöscht.');

        return Response::redirect(self::ZIEL);
    }

    /** The question before "Bestand leeren" (06 section 4, "Rückfragen"). */
    public function leerenFragen(): Response
    {
        if (!$this->import->kannLeeren()) {
            return $this->zurueck('Es gibt schon Jahresbeiträge. Der Bestand kann nicht mehr geleert werden.', FlashArt::Fehler);
        }

        return Response::html($this->dienste->view->render('admin/import-leeren', [
            'title' => 'Bestand leeren',
            'anzahl' => $this->import->anzahlMitglieder(),
            'ziel' => self::ZIEL,
            'aktion' => self::PFAD_LEEREN,
        ], Area::Admin));
    }

    public function leeren(Request $request): Response
    {
        try {
            $anzahl = $this->import->leeren($this->dienste->session->userId(), $request->ip);
        } catch (ImportNichtMoeglich $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        $this->dienste->session->flash(sprintf('Der Bestand ist geleert (%d Mitglieder gelöscht). Eine neue Datei kann hochgeladen werden.', $anzahl));

        return Response::redirect(self::ZIEL);
    }

    private function zurueck(string $meldung, FlashArt $art): Response
    {
        $this->dienste->session->flash($meldung, $art);

        return Response::redirect(self::ZIEL);
    }

    /**
     * @param array<string, mixed>|null $datei one entry of $_FILES
     */
    private function pruefeDatei(?array $datei): ?string
    {
        $fehler = (int) ($datei['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($datei === null || $fehler === UPLOAD_ERR_NO_FILE) {
            return 'Bitte die Datei import.csv auswählen.';
        }
        if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
            return 'Die Datei ist zu groß.';
        }
        $pfad = (string) ($datei['tmp_name'] ?? '');
        if ($fehler !== UPLOAD_ERR_OK || $pfad === '' || !is_file($pfad)) {
            return 'Die Datei konnte nicht hochgeladen werden. Bitte noch einmal versuchen.';
        }
        if ((int) filesize($pfad) > ErstImport::MAX_BYTES) {
            return 'Die Datei ist zu groß (höchstens ' . intdiv(ErstImport::MAX_BYTES, 1024 * 1024) . ' MB).';
        }

        return null;
    }
}
