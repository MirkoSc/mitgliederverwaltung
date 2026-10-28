<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\Backup\Wiederherstellung;

/**
 * Housekeeping of shared/var/backups/ (docs/spec/01-plattform.md section 5:
 * "alte Backups"): an abandoned backup chain is discarded - its work file
 * holds the database dump in the clear and must not lie around -, so is an
 * abandoned restore with its decrypted backup, and the backups beyond the
 * newest ten are deleted, should a backup ever have finished without
 * rotating.
 */
final readonly class BackupAufraeumenTask implements CronTask
{
    public function __construct(
        private BackupErstellung $erstellung,
        private BackupService $backups,
        private Wiederherstellung $wiederherstellung,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(BackupErstellung::aus($dienste), BackupService::aus($dienste), Wiederherstellung::fuer($dienste->paths));
    }

    public function name(): string
    {
        return 'backups_aufraeumen';
    }

    public function istAufraeumen(): bool
    {
        return true;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return ($this->erstellung->verwaistAufraeumen() ? 1 : 0)
            + ($this->wiederherstellung->verwaistAufraeumen($now) ? 1 : 0)
            + $this->backups->rotate();
    }
}
