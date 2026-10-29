<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\JobStatus;
use App\Domain\Permission;
use App\Service\Import\Abgleich;
use App\Service\Import\ErstImport;

/**
 * Settings section Import (docs/spec/10-import.md sections 1 and 4). The
 * states, told apart here so the template only shows:
 *  - `hochladen`: the stock is empty and nothing is uploaded - the upload form;
 *  - `vorschau`: a file is uploaded - the preview with errors and hints;
 *  - `laeuft`: the chain was started - progress, "fortsetzen" after an interruption;
 *  - `bestand`: members exist - the upload form for a reconciliation, what
 *    the last import and reconciliation did, "Bestand leeren";
 *  - `abgleich`: a file for a reconciliation is uploaded - the merge view;
 *  - `abgleich-laeuft`: the reconciliation chain was started.
 * The forms post to app/routes/import.php; the step chains run in
 * public/js/import.js and public/js/abgleich.js.
 */
final readonly class ImportAbschnitt implements Abschnitt
{
    public function __construct(
        private ErstImport $import,
        private Abgleich $abgleich,
        private string $version,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(ErstImport::aus($dienste), Abgleich::aus($dienste), $dienste->version->value);
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
            return $this->abgleichDaten($grund);
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

    /**
     * The states of a stock that is not empty: the reconciliation.
     *
     * @param array<string, mixed> $grund
     * @return array<string, mixed>
     */
    private function abgleichDaten(array $grund): array
    {
        $job = $this->abgleich->aktuellerJob();
        $phase = (string) ($job?->state['phase'] ?? '');
        $grund = [
            ...$grund,
            'zustand' => 'bestand',
            'abgleichStand' => $job?->state ?? [],
            'abgleichZuletzt' => $job !== null && $job->status === JobStatus::Fertig ? $job : null,
            'abgleichVorschau' => null,
        ];

        if ($phase === 'abgleich') {
            return [...$grund, 'zustand' => 'abgleich-laeuft'];
        }
        if ($phase === 'vorschau') {
            $vorschau = $this->abgleich->vorschau();
            if ($vorschau !== null) {
                return [...$grund, 'zustand' => 'abgleich', 'abgleichVorschau' => $vorschau, 'heute' => new \DateTimeImmutable('today')];
            }

            return [...$grund, 'dateiFehlt' => true];
        }

        return $grund;
    }
}
