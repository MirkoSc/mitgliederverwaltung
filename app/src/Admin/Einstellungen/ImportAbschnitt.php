<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\JobStatus;
use App\Domain\Permission;
use App\Service\Import\ErstImport;

/**
 * Settings section Import (docs/spec/10-import.md section 1). Four states,
 * told apart here so the template only shows:
 *  - `hochladen`: the stock is empty and nothing is uploaded - the upload form;
 *  - `vorschau`: a file is uploaded - the preview with errors and hints;
 *  - `laeuft`: the chain was started - progress, "fortsetzen" after an interruption;
 *  - `bestand`: members exist - what the last import did, "Bestand leeren".
 * The forms post to app/routes/import.php; the step chain runs in
 * public/js/import.js.
 */
final readonly class ImportAbschnitt implements Abschnitt
{
    public function __construct(
        private ErstImport $import,
        private string $version,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(ErstImport::aus($dienste), $dienste->version->value);
    }

    public function schluessel(): string
    {
        return 'import';
    }

    public function titel(): string
    {
        return 'Import';
    }

    public function reihenfolge(): int
    {
        return 80;
    }

    public function recht(): Permission
    {
        return Permission::AdminImport;
    }

    public function template(): string
    {
        return 'admin/einstellungen/import';
    }

    public function daten(): array
    {
        $job = $this->import->aktuellerJob();
        $phase = (string) ($job?->state['phase'] ?? '');
        $grund = [
            'zustand' => 'hochladen',
            'vorschau' => null,
            'job' => $job,
            'stand' => $job?->state ?? [],
            'zuletzt' => $job !== null && $job->status === JobStatus::Fertig ? $job : null,
            'kannLeeren' => $this->import->kannLeeren(),
            // The settings page renders sections as fragments without a
            // script list; the section brings its own (external) script.
            'version' => $this->version,
        ];

        if ($phase === 'import') {
            return [...$grund, 'zustand' => 'laeuft'];
        }
        if (!$this->import->bestandIstLeer()) {
            return [...$grund, 'zustand' => 'bestand'];
        }
        if ($phase === 'vorschau') {
            $vorschau = $this->import->vorschau();
            if ($vorschau !== null) {
                return [...$grund, 'zustand' => 'vorschau', 'vorschau' => $vorschau];
            }
            // The file is gone: the upload form with an explanation.
            return [...$grund, 'dateiFehlt' => true];
        }

        return $grund;
    }
}
