<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Service\Import\Abgleich;
use App\Service\Import\ErstImport;
use App\Service\Import\ImportLaeuftBereits;
use App\Service\Import\ImportNichtMoeglich;
use App\View\Area;
use App\Support\Datum;
use App\View\FlashArt;

/**
 * The routes of the settings section Import (docs/spec/10-import.md
 * sections 1 and 4): upload import.csv - into an empty stock as the first
 * import, else as a reconciliation -, start each chain and drive it one step
 * per request (public/js/import.js, public/js/abgleich.js), drop the upload,
 * empty the stock.
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
        private Abgleich $abgleich,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, ErstImport::aus($dienste), Abgleich::aus($dienste));
    }

    public function hochladen(Request $request): Response
    {
        $datei = $request->files['datei'] ?? null;
        $fehler = $this->pruefeDatei(is_array($datei) ? $datei : null);
        if ($fehler !== null) {
            return $this->zurueck($fehler, FlashArt::Fehler);
        }

        // An empty stock takes the first import, any other the reconciliation (10 section 4).
        $erstimport = $this->import->bestandIstLeer();
        try {
            $inhalt = (string) file_get_contents((string) $datei['tmp_name']);
            $name = (string) ($datei['name'] ?? '');
            $userId = $this->dienste->session->userId();
            $erstimport ? $this->import->hochladen($inhalt, $name, $userId) : $this->abgleich->hochladen($inhalt, $name, $userId);
        } catch (ImportNichtMoeglich $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }

        $this->dienste->session->flash($erstimport
            ? 'Die Datei ist hochgeladen. Unten steht die Vorschau – importiert ist noch nichts.'
            : 'Die Datei ist hochgeladen. Unten steht der Abgleich – übernommen ist noch nichts.');

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

    /**
     * Starts the reconciliation with the ticked rows. public/js/abgleich.js
     * sends each section as one comma-separated list (a form with thousands
     * of checkboxes would run into max_input_vars); arrays are read too.
     */
    public function abgleichStarten(Request $request): ResponseInterface
    {
        $auswahl = [];
        foreach (['neu', 'aendern', 'austritt', 'loeschen'] as $abschnitt) {
            $auswahl[$abschnitt] = self::nummern($request->post[$abschnitt] ?? []);
        }
        $datum = Datum::ausEingabe((string) ($request->post['austrittsdatum'] ?? ''));

        try {
            $this->abgleich->starten($auswahl, $datum);
        } catch (ImportNichtMoeglich | ImportLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json(['phase' => 'abgleich', 'fertig' => false, 'offset' => 0, 'gesamt' => 0]);
    }

    /** One step of the reconciliation chain. */
    public function abgleichSchritt(Request $request): ResponseInterface
    {
        try {
            $stand = $this->abgleich->schritt($request->ip);
        } catch (ImportNichtMoeglich | ImportLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            $this->dienste->logger->append('Reconciliation step failed: ' . $e::class);

            return Response::json(['fehler' => 'Der Schritt ist fehlgeschlagen. Bitte noch einmal versuchen – bereits Übernommenes wird nicht doppelt ausgeführt.'], 500);
        }

        if ($stand['fertig'] === true) {
            $this->dienste->session->flash(self::zusammenfassung($stand));
        }

        return Response::json($stand);
    }

    public function abgleichVerwerfen(): Response
    {
        $this->abgleich->verwerfen();
        $this->dienste->session->flash('Der Abgleich ist abgebrochen und die hochgeladene Datei gelöscht.');

        return Response::redirect(self::ZIEL);
    }

    /**
     * "x neu, y ausgetreten, z geändert" (10 section 4).
     *
     * @param array<string, mixed> $stand
     */
    public static function zusammenfassung(array $stand): string
    {
        $teile = [
            sprintf('%d neu', (int) ($stand['neu'] ?? 0)),
            sprintf('%d ausgetreten', (int) ($stand['ausgetreten'] ?? 0)),
            sprintf('%d geändert', (int) ($stand['geaendert'] ?? 0)),
        ];
        if ((int) ($stand['geloescht'] ?? 0) > 0) {
            $teile[] = sprintf('%d gelöscht', (int) $stand['geloescht']);
        }
        if ((int) ($stand['uebersprungen'] ?? 0) > 0) {
            $teile[] = sprintf('%d übersprungen', (int) $stand['uebersprungen']);
        }

        return 'Der Abgleich ist abgeschlossen: ' . implode(', ', $teile) . '.';
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
     * Member numbers from "1,2,3" or a list of values; anything else is dropped.
     *
     * @return list<int>
     */
    private static function nummern(mixed $wert): array
    {
        $werte = is_array($wert) ? $wert : explode(',', is_string($wert) ? $wert : '');
        $nummern = [];
        foreach ($werte as $einzeln) {
            $text = is_scalar($einzeln) ? trim((string) $einzeln) : '';
            if ($text !== '' && ctype_digit($text)) {
                $nummern[] = (int) $text;
            }
        }

        return array_values(array_unique($nummern));
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
