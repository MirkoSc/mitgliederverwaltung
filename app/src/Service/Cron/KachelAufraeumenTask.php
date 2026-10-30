<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Service\Karte\KachelProxy;

/**
 * Keeps the map tile cache (shared/var/kacheln/, issue #91) within its
 * expiry and size cap.
 */
final readonly class KachelAufraeumenTask implements CronTask
{
    public function __construct(private KachelProxy $kacheln)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(KachelProxy::aus($dienste));
    }

    public function name(): string
    {
        return 'kacheln_aufraeumen';
    }

    public function istAufraeumen(): bool
    {
        return true;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->kacheln->aufraeumen($now);
    }
}
