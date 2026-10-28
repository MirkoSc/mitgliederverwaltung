<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;

/**
 * Housekeeping of shared/var/backups/ (docs/spec/01-plattform.md section 5:
 * "alte Backups"): an abandoned backup chain is discarded - its work file
 * holds the database dump in the clear and must not lie around - and the
 * backups beyond the newest ten are deleted, should a backup ever have
 * finished without rotating.
 */
final readonly class BackupAufraeumenTask implements CronTask
{
    public function __construct(
        private BackupErstellung $erstellung,
        private BackupService $backups,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(BackupErstellung::aus($dienste), BackupService::aus($dienste));
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
        return ($this->erstellung->verwaistAufraeumen() ? 1 : 0) + $this->backups->rotate();
    }
}
