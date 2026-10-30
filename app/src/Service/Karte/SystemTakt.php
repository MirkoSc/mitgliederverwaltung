<?php

declare(strict_types=1);

namespace App\Service\Karte;

/** The real clock. */
final readonly class SystemTakt implements Takt
{
    public function jetzt(): float
    {
        return microtime(true);
    }

    public function warten(float $sekunden): void
    {
        if ($sekunden > 0) {
            usleep((int) ceil($sekunden * 1_000_000));
        }
    }
}
