<?php

declare(strict_types=1);

namespace App\Service\Statistik;

use App\Service\Beitraege\Verteilung;

/** The fee statistics of one year (09 section 7), computed by Beitragsstatistik. */
final readonly class Beitragszahlen
{
    /** @param list<Auswertung> $auswertungen in display order */
    public function __construct(
        /** Total, basis (created or projected), by fee type and department. */
        public Verteilung $verteilung,
        public array $auswertungen,
    ) {
    }
}
