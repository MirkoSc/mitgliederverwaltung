<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Http\Request;
use App\Http\Response;
use App\Service\Verein\LogoException;
use App\Service\Verein\LogoService;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\VereinsdatenAustausch;
use App\Service\Verein\VereinsdatenImportException;
use App\Service\Verein\Vereinsdaten;
use App\View\FlashArt;

/**
 * The forms of the settings section Verein (docs/spec/11-vereinsdaten.md):
 * club data, associations, logo. Each answers with a redirect back to the
 * section and a flash message (post/redirect/get). Rules are hints: the
 * entry is saved and the message says what looks wrong.
 */
final readonly class VereinController
{
    private const string ZIEL = '/admin#verein';

    public function __construct(
        private Dienste $dienste,
        private VereinRepository $verein,
        private LogoService $logo,
        private VereinsdatenAustausch $austausch,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, VereinRepository::aus($dienste), LogoService::aus($dienste), VereinsdatenAustausch::aus($dienste));
    }

    public function speichern(Request $request): Response
    {
        $feld = static fn(string $name): string => $request->feld($name);
        $daten = Vereinsdaten::ausEingabe($feld);
        $this->verein->speichern($daten);

        $hinweise = $daten->hinweise(Vereinsdaten::ungueltigeDaten($feld));
        if ($hinweise === []) {
            $this->dienste->session->flash('Die Vereinsdaten sind gespeichert.');
        } else {
            $this->dienste->session->flash('Die Vereinsdaten sind gespeichert. Bitte prüfen: ' . implode(' ', $hinweise), FlashArt::Warnung);
        }

        return Response::redirect(self::ZIEL);
    }

    public function verbaende(Request $request): Response
    {
        $eingabe = $request->post['verband'] ?? [];
        $zeilen = [];
        foreach (is_array($eingabe) ? array_values($eingabe) : [] as $index => $zeile) {
            if (!is_array($zeile)) {
                continue;
            }
            $text = static fn(string $name, int $laenge): string => mb_substr(is_string($zeile[$name] ?? null) ? trim($zeile[$name]) : '', 0, $laenge);
            $name = $text('name', 120);
            if ($name === '') {
                continue; // no name: new empty rows are ignored, existing rows are deleted
            }
            $position = is_string($zeile['position'] ?? null) && ctype_digit(trim($zeile['position'])) ? (int) trim($zeile['position']) : null;
            $zeilen[] = [
                'id' => is_string($zeile['id'] ?? null) && ctype_digit($zeile['id']) ? (int) $zeile['id'] : 0,
                'name' => $name,
                'vereinsnummer' => $text('vereinsnummer', 60),
                'bemerkung' => $text('bemerkung', 255),
                // Rows without a position keep their place in the form.
                'sortierung' => $position ?? ($index + 1),
                'index' => $index,
            ];
        }
        usort($zeilen, static fn(array $a, array $b): int => [$a['sortierung'], $a['index']] <=> [$b['sortierung'], $b['index']]);
        $this->verein->verbaendeErsetzen(array_map(
            static fn(array $z): array => ['id' => $z['id'], 'name' => $z['name'], 'vereinsnummer' => $z['vereinsnummer'], 'bemerkung' => $z['bemerkung']],
            $zeilen,
        ));
        $this->dienste->session->flash('Die Verbände sind gespeichert.');

        return Response::redirect(self::ZIEL);
    }

    public function logoHochladen(Request $request): Response
    {
        $datei = $request->files['logo'] ?? null;
        $fehler = is_array($datei) && is_int($datei['error'] ?? null) ? $datei['error'] : UPLOAD_ERR_NO_FILE;
        $pfad = is_array($datei) && is_string($datei['tmp_name'] ?? null) ? $datei['tmp_name'] : '';

        try {
            match (true) {
                $fehler === UPLOAD_ERR_NO_FILE => throw new LogoException('Bitte eine Bilddatei auswählen.'),
                $fehler === UPLOAD_ERR_INI_SIZE, $fehler === UPLOAD_ERR_FORM_SIZE => throw new LogoException('Die Datei ist zu groß (höchstens 5 MB).'),
                $fehler !== UPLOAD_ERR_OK => throw new LogoException('Die Datei konnte nicht hochgeladen werden.'),
                default => $this->logo->speichern($pfad),
            };
        } catch (LogoException $e) {
            $this->dienste->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect(self::ZIEL);
        }
        $this->dienste->session->flash('Das Logo ist gespeichert.');

        return Response::redirect(self::ZIEL);
    }

    public function logoEntfernen(Request $request): Response
    {
        $this->logo->entfernen();
        $this->dienste->session->flash('Das Logo ist entfernt.');

        return Response::redirect(self::ZIEL);
    }

    /** Download of the club data as one JSON file (11 section 5); made now, never stored. */
    public function exportieren(Request $request): Response
    {
        $jetzt = new \DateTimeImmutable();
        $inhalt = $this->austausch->exportieren($jetzt);
        $this->dienste->audit()->protokollieren(AuditAktion::VereinExportiert, $this->dienste->session->userId(), ip: $request->ip);

        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->austausch->dateiname($jetzt) . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $inhalt);
    }

    /** Replaces the club data, associations and logo by the uploaded file (11 section 5). */
    public function importieren(Request $request): Response
    {
        $datei = $request->files['datei'] ?? null;
        $fehler = is_array($datei) && is_int($datei['error'] ?? null) ? $datei['error'] : UPLOAD_ERR_NO_FILE;
        $pfad = is_array($datei) && is_string($datei['tmp_name'] ?? null) ? $datei['tmp_name'] : '';
        $name = is_array($datei) && is_string($datei['name'] ?? null) ? $datei['name'] : '';

        try {
            match (true) {
                $request->feld('ersetzen') !== '1' => throw new VereinsdatenImportException('Bitte bestätigen, dass die vorhandenen Vereinsdaten vollständig ersetzt werden. Es wurde nichts geändert.'),
                $fehler === UPLOAD_ERR_NO_FILE => throw new VereinsdatenImportException('Bitte eine Datei auswählen.'),
                $fehler === UPLOAD_ERR_INI_SIZE, $fehler === UPLOAD_ERR_FORM_SIZE => throw new VereinsdatenImportException('Die Datei ist zu groß (höchstens 10 MB).'),
                $fehler !== UPLOAD_ERR_OK => throw new VereinsdatenImportException('Die Datei konnte nicht hochgeladen werden.'),
                !str_ends_with(strtolower($name), '.json') => throw new VereinsdatenImportException('Bitte eine Datei mit der Endung .json wählen.'),
                default => null,
            };
            $inhalt = is_file($pfad) && filesize($pfad) <= VereinsdatenAustausch::MAX_BYTES ? file_get_contents($pfad) : false;
            if ($inhalt === false) {
                throw new VereinsdatenImportException('Die Datei konnte nicht gelesen werden oder ist zu groß (höchstens 10 MB).');
            }
            $hinweise = $this->austausch->importieren($inhalt);
        } catch (VereinsdatenImportException $e) {
            $this->dienste->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect(self::ZIEL);
        }
        $this->dienste->audit()->protokollieren(AuditAktion::VereinImportiert, $this->dienste->session->userId(), ip: $request->ip);
        if ($hinweise === []) {
            $this->dienste->session->flash('Die Vereinsdaten sind importiert.');
        } else {
            $this->dienste->session->flash('Die Vereinsdaten sind importiert. Bitte prüfen: ' . implode(' ', $hinweise), FlashArt::Warnung);
        }

        return Response::redirect(self::ZIEL);
    }
}
