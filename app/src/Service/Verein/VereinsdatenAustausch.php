<?php

declare(strict_types=1);

namespace App\Service\Verein;

use App\Dienste;
use App\Domain\Verband;

/**
 * Export and import of the section Verein as one JSON file
 * (docs/spec/11-vereinsdaten.md section 5): the fields, the associations
 * and the logo - nothing else, no secrets. Made for a new installation, so
 * the club data need not be typed again. The file is made on download and
 * never stored on the webspace.
 *
 * Import replaces: everything is checked first, and a file that does not
 * pass changes nothing. Only a broken logo is left out, with a hint.
 */
final readonly class VereinsdatenAustausch
{
    public const string KENNUNG = 'mitgliederverwaltung-vereinsdaten';

    public const int VERSION = 1;

    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const int MAX_VERBAENDE = 100;

    public function __construct(
        private \PDO $pdo,
        private VereinRepository $verein,
        private LogoService $logo,
        private string $appVersion,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->pdo(),
            VereinRepository::aus($d),
            LogoService::aus($d),
            $d->version->value,
        ));
    }

    /** The file name: vereinsdaten-<short name>-YYYY-MM-DD.json, without the short name if nothing is left of it. */
    public function dateiname(\DateTimeImmutable $jetzt): string
    {
        $kurz = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($this->verein->get()->wert('kurzname'))) ?? '', '-');

        return 'vereinsdaten-' . ($kurz === '' ? '' : $kurz . '-') . $jetzt->format('Y-m-d') . '.json';
    }

    /** Compact JSON of the club data, associations (in order) and logo. */
    public function exportieren(\DateTimeImmutable $jetzt): string
    {
        $logo = [];
        foreach ($this->logo->alsBytes() as $groesse => $bytes) {
            $logo[$groesse] = base64_encode($bytes);
        }

        return json_encode([
            'format' => self::KENNUNG,
            'version' => self::VERSION,
            'app_version' => $this->appVersion,
            'erzeugt' => $jetzt->format('c'),
            'verein' => $this->verein->get()->alsListe(),
            'verbaende' => array_map(
                static fn(Verband $v): array => ['name' => $v->name, 'vereinsnummer' => $v->vereinsnummer, 'bemerkung' => $v->bemerkung],
                $this->verein->verbaende(),
            ),
            'logo' => $logo === [] ? null : $logo,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Replaces the club data, the associations and the logo by the file's.
     *
     * @return list<string> hints: what to check afterwards (the data is imported anyway)
     * @throws VereinsdatenImportException nothing has been changed
     */
    public function importieren(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new VereinsdatenImportException('Die Datei ist zu groß (höchstens 10 MB).');
        }
        try {
            $datei = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $datei = null;
        }
        if (!is_array($datei) || ($datei['format'] ?? null) !== self::KENNUNG) {
            throw new VereinsdatenImportException('Das ist keine Datei mit Vereinsdaten. Bitte eine Datei wählen, die hier mit „Vereinsdaten exportieren“ erzeugt wurde.');
        }
        $version = $datei['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new VereinsdatenImportException('Die Datei hat keine gültige Formatversion.');
        }
        if ($version > self::VERSION) {
            throw new VereinsdatenImportException('Die Datei stammt aus einer neueren Version der Anwendung. Bitte erst diese Installation aktualisieren.');
        }

        $werte = $this->felder($datei['verein'] ?? []);
        $verbaende = $this->verbaende($datei['verbaende'] ?? []);
        $logoBytes = null;
        $logoHinweis = null;
        if (($datei['logo'] ?? null) !== null) {
            try {
                $logoBytes = $this->logo->pruefen($datei['logo']);
            } catch (LogoException $e) {
                $logoHinweis = 'Das Logo wurde nicht übernommen: ' . $e->getMessage();
            }
        }

        $daten = Vereinsdaten::ausEingabe(static fn(string $name): string => $werte[$name] ?? '');
        $hinweise = $daten->hinweise(Vereinsdaten::ungueltigeDaten(static fn(string $name): string => $werte[$name] ?? ''));

        $this->pdo->beginTransaction();
        try {
            $this->verein->speichern($daten);
            $this->verein->verbaendeNeu($verbaende);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        // Files last, and only after the check: a file that did not pass
        // never touches the current logo - without a logo in the file the
        // old one is removed (replace), with a broken one it stays.
        if ($logoHinweis === null) {
            try {
                $logoBytes === null ? $this->logo->entfernen() : $this->logo->ausBytes($logoBytes);
            } catch (LogoException $e) {
                $logoHinweis = 'Das Logo wurde nicht übernommen: ' . $e->getMessage();
            }
        }
        if ($logoHinweis !== null) {
            $hinweise[] = $logoHinweis;
        }

        return $hinweise;
    }

    /**
     * @return array<string, string> only the known fields; unknown keys are ignored
     * @throws VereinsdatenImportException
     */
    private function felder(mixed $verein): array
    {
        if (!is_array($verein) || ($verein !== [] && array_is_list($verein))) {
            throw new VereinsdatenImportException('Die Datei enthält keine Vereinsdaten (Abschnitt „verein“).');
        }
        $werte = [];
        foreach (Vereinsdaten::FELDER as $schluessel => $bezeichnung) {
            $wert = $verein[$schluessel] ?? '';
            if (!is_string($wert)) {
                throw new VereinsdatenImportException('Das Feld „' . $bezeichnung . '“ in der Datei ist kein Text.');
            }
            $werte[$schluessel] = $wert;
        }

        return $werte;
    }

    /**
     * @return list<array{name: string, vereinsnummer: string, bemerkung: string}> without nameless rows, shortened like the form
     * @throws VereinsdatenImportException
     */
    private function verbaende(mixed $liste): array
    {
        if (!is_array($liste) || !array_is_list($liste)) {
            throw new VereinsdatenImportException('Die Verbände in der Datei sind keine Liste.');
        }
        if (count($liste) > self::MAX_VERBAENDE) {
            throw new VereinsdatenImportException('Die Datei enthält zu viele Verbände (höchstens ' . self::MAX_VERBAENDE . ').');
        }
        $zeilen = [];
        foreach ($liste as $zeile) {
            if (!is_array($zeile)) {
                throw new VereinsdatenImportException('Ein Verband in der Datei ist ungültig.');
            }
            $text = static function (string $schluessel, int $laenge) use ($zeile): string {
                $wert = $zeile[$schluessel] ?? '';
                if (!is_string($wert)) {
                    throw new VereinsdatenImportException('Ein Verband in der Datei hat ein Feld, das kein Text ist.');
                }

                return mb_substr(trim($wert), 0, $laenge);
            };
            $name = $text('name', 120);
            if ($name === '') {
                continue;
            }
            $zeilen[] = ['name' => $name, 'vereinsnummer' => $text('vereinsnummer', 60), 'bemerkung' => $text('bemerkung', 255)];
        }

        return $zeilen;
    }
}
