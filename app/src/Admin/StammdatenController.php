<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Domain\Abteilung;
use App\Domain\AktivArt;
use App\Domain\Beitragsart;
use App\Domain\BeitragsRegel;
use App\Domain\Funktion;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FunktionRepository;
use App\Repository\SettingRepository;
use App\Support\Geld;
use App\View\FlashArt;

/**
 * The forms of the settings sections Abteilungen, Funktionen, Beitragsarten,
 * runde Geburtstage and Ehrungsstufen (docs/spec/07-mitglieder.md sections 2
 * and 4, 08-beitraege.md section 1, 09-listen-und-exporte.md section 3).
 * Each answers with a redirect back to its section and a flash message.
 *
 * The list forms follow the Verbände form: one row per entry, new rows are
 * empty rows at the end, a row without name is ignored, "löschen" deletes an
 * entry nobody uses and deactivates one that is in use.
 */
final readonly class StammdatenController
{
    public const int MAX_ALTER = 150;

    public function __construct(
        private Dienste $dienste,
        private AbteilungRepository $abteilungen,
        private FunktionRepository $funktionen,
        private BeitragsartRepository $beitragsarten,
        private SettingRepository $einstellungen,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        $pdo = $dienste->pdo();

        return new self(
            $dienste,
            new AbteilungRepository($pdo),
            new FunktionRepository($pdo),
            new BeitragsartRepository($pdo),
            new SettingRepository($pdo),
        );
    }

    public function abteilungen(Request $request): Response
    {
        $deaktiviert = [];
        foreach ($this->zeilen($request) as $z) {
            $alt = $z['id'] > 0 ? $this->abteilungen->finde($z['id']) : null;
            if ($z['loeschen'] && $alt !== null) {
                $this->abteilungen->loeschen($z['id']) ?: $deaktiviert[] = $alt->name;
                continue;
            }
            $neu = new Abteilung($z['name'], $this->text($z, 'blsv_nummer', 8), $z['sortierung'], $z['aktiv'], $alt?->id);
            $this->abteilungen->speichern($neu);
        }

        return $this->fertig('abteilungen', 'Die Abteilungen sind gespeichert.', $deaktiviert);
    }

    public function funktionen(Request $request): Response
    {
        $deaktiviert = [];
        foreach ($this->zeilen($request) as $z) {
            $alt = $z['id'] > 0 ? $this->funktionen->finde($z['id']) : null;
            if ($z['loeschen'] && $alt !== null) {
                $this->funktionen->loeschen($z['id']) ?: $deaktiviert[] = $alt->name;
                continue;
            }
            $this->funktionen->speichern(new Funktion($z['name'], isset($z['roh']['mit_lizenz']), $z['sortierung'], $z['aktiv'], $alt?->id));
        }

        return $this->fertig('funktionen', 'Die Funktionen sind gespeichert.', $deaktiviert);
    }

    public function beitragsarten(Request $request): Response
    {
        $deaktiviert = [];
        $hinweise = [];
        foreach ($this->zeilen($request) as $z) {
            $alt = $z['id'] > 0 ? $this->beitragsarten->finde($z['id']) : null;
            if ($z['loeschen'] && $alt !== null) {
                $this->beitragsarten->loeschen($z['id']) ?: $deaktiviert[] = $alt->name;
                continue;
            }
            $betrag = Geld::ausEingabe($this->text($z, 'betrag', 20));
            if ($betrag === null || $betrag < 0) {
                // A hint, not a block: keep the old amount (0 for a new type) and say so.
                $hinweise[] = 'Der Betrag von „' . $z['name'] . '“ ist keine gültige Zahl und wurde nicht geändert.';
                $betrag = $alt->betragCent ?? 0;
            }
            $regel = BeitragsRegel::tryFrom($this->text($z, 'regel', 20));
            $aktivArt = AktivArt::tryFrom($this->text($z, 'aktiv_art', 20)) ?? AktivArt::Egal;
            $this->beitragsarten->speichern(new Beitragsart($z['name'], $betrag, $regel, $aktivArt, $z['sortierung'], $z['aktiv'], $alt?->id));
        }

        return $this->fertig('beitragsarten', 'Die Beitragsarten sind gespeichert.', $deaktiviert, $hinweise);
    }

    public function rundeGeburtstage(Request $request): Response
    {
        return $this->zahlenliste($request, 'runde_geburtstage', 'geburtstage', 'Die runden Geburtstage sind gespeichert.');
    }

    public function ehrungsstufen(Request $request): Response
    {
        return $this->zahlenliste($request, 'ehrungsstufen', 'ehrungsstufen', 'Die Ehrungsstufen sind gespeichert.');
    }

    private function zahlenliste(Request $request, string $einstellung, string $abschnitt, string $meldung): Response
    {
        $zahlen = [];
        $unbrauchbar = [];
        foreach (preg_split('/[\s,;]+/', $request->feld('werte'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $teil) {
            if (ctype_digit($teil) && (int) $teil >= 1 && (int) $teil <= self::MAX_ALTER) {
                $zahlen[(int) $teil] = (int) $teil;
            } else {
                $unbrauchbar[] = $teil;
            }
        }
        sort($zahlen);
        $this->einstellungen->set($einstellung, array_values($zahlen));

        $hinweise = $unbrauchbar === [] ? [] : [
            'Nicht übernommen, weil keine Zahl von 1 bis ' . self::MAX_ALTER . ': ' . implode(', ', $unbrauchbar) . '.',
        ];

        return $this->fertig($abschnitt, $meldung, [], $hinweise);
    }

    /**
     * @param list<string> $deaktiviert names that could not be deleted
     * @param list<string> $hinweise
     */
    private function fertig(string $abschnitt, string $meldung, array $deaktiviert, array $hinweise = []): Response
    {
        if ($deaktiviert !== []) {
            $hinweise[] = 'Schon Mitgliedern zugeordnet, daher nur deaktiviert statt gelöscht: ' . implode(', ', $deaktiviert) . '.';
        }
        $this->dienste->session->flash(
            $hinweise === [] ? $meldung : $meldung . ' ' . implode(' ', $hinweise),
            $hinweise === [] ? FlashArt::Ok : FlashArt::Warnung,
        );

        return Response::redirect('/admin#' . $abschnitt);
    }

    /**
     * The rows of a list form, sorted by position. Rows without name are
     * dropped, except existing ones marked for deletion.
     *
     * @return list<array{id: int, name: string, sortierung: int, aktiv: bool, loeschen: bool, roh: array<string, mixed>}>
     */
    private function zeilen(Request $request): array
    {
        $eingabe = $request->post['zeile'] ?? [];
        $zeilen = [];
        foreach (is_array($eingabe) ? array_values($eingabe) : [] as $index => $zeile) {
            if (!is_array($zeile)) {
                continue;
            }
            $id = is_string($zeile['id'] ?? null) && ctype_digit($zeile['id']) ? (int) $zeile['id'] : 0;
            $name = mb_substr(is_string($zeile['name'] ?? null) ? trim($zeile['name']) : '', 0, 100);
            $loeschen = isset($zeile['loeschen']);
            if ($name === '' && !($loeschen && $id > 0)) {
                continue;
            }
            $position = is_string($zeile['position'] ?? null) && ctype_digit(trim($zeile['position'])) ? (int) trim($zeile['position']) : $index + 1;
            $zeilen[] = [
                'id' => $id,
                'name' => $name,
                'sortierung' => $position,
                'aktiv' => isset($zeile['aktiv']),
                'loeschen' => $loeschen,
                'roh' => $zeile,
                'index' => $index,
            ];
        }
        usort($zeilen, static fn(array $a, array $b): int => [$a['sortierung'], $a['index']] <=> [$b['sortierung'], $b['index']]);

        return array_map(static function (array $z): array {
            unset($z['index']);

            return $z;
        }, $zeilen);
    }

    /**
     * @param array{roh: array<string, mixed>} $zeile
     */
    private function text(array $zeile, string $feld, int $laenge): string
    {
        $wert = $zeile['roh'][$feld] ?? '';

        return mb_substr(is_string($wert) ? trim($wert) : '', 0, $laenge);
    }
}
