<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/** The member statistics of one day (09 section 7), computed by Mitgliederstatistik. */
final readonly class Mitgliederzahlen
{
    /** @param list<Auswertung> $auswertungen in display order */
    public function __construct(
        public \DateTimeImmutable $stichtag,
        public Kennzahlen $kennzahlen,
        public array $auswertungen,
    ) {
    }
}
