<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Repository\ZweiterFaktorRepository;

/**
 * Sweeps what the second factor leaves behind: mail codes and remembered
 * devices that ran out. They open nothing any more - keeping them would be
 * hashes kept for nothing.
 */
final readonly class ZweiterFaktorCleanupTask implements CronTask
{
    public function __construct(private ZweiterFaktorRepository $faktoren)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(new ZweiterFaktorRepository($dienste->pdo()));
    }

    public function name(): string
    {
        return 'zweiter_faktor_aufraeumen';
    }

    public function istAufraeumen(): bool
    {
        return true;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->faktoren->loescheAbgelaufeneMailCodes($now) + $this->faktoren->loescheAbgelaufeneGeraete($now);
    }
}
