<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;

/**
 * Settings section Backups (docs/spec/04-installation-und-update.md
 * section 6): make a backup as a step chain, download the last ten. Restoring
 * happens in the installer, not here. The forms post to app/routes/backups.php.
 */
final readonly class BackupAbschnitt implements Abschnitt
{
    public function __construct(
        private BackupService $backups,
        private BackupErstellung $erstellung,
        private string $version,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(BackupService::aus($dienste), BackupErstellung::aus($dienste), $dienste->version->value);
    }

    public function schluessel(): string
    {
        return 'backups';
    }

    public function titel(): string
    {
        return 'Backups';
    }

    public function reihenfolge(): int
    {
        return 90;
    }

    public function recht(): Permission
    {
        return Permission::AdminSystem;
    }

    public function template(): string
    {
        return 'admin/einstellungen/backups';
    }

    public function daten(): array
    {
        $stand = $this->erstellung->stand();

        return [
            'backups' => $this->backups->list(),
            'laeuft' => $this->erstellung->laeuft(),
            // An unfinished chain - running or abandoned - can be cancelled.
            'unfertig' => $stand !== null && $stand['phase'] !== BackupErstellung::PHASE_FERTIG,
            'behalten' => BackupService::KEEP,
            // The settings page renders sections as fragments without a
            // script list; the section brings its own (external) script.
            'version' => $this->version,
        ];
    }
}
