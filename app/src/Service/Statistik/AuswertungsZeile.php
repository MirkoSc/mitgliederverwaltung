<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/** One row of an Auswertung: a group with one value per series. */
final readonly class AuswertungsZeile
{
    /**
     * @param list<int> $werte one per series of the Auswertung; money in integer cent
     */
    public function __construct(
        public string $name,
        public array $werte,
        /** A rest row ("ohne Angabe", "Sonstige"): shown muted, drawn grey. */
        public bool $ohne = false,
        /** The column "anzahlTitel" (e.g. the people behind a sum); null without it. */
        public ?int $anzahl = null,
        /** The column "zusatzTitel" as text (e.g. "41,3"); null without it. */
        public ?string $zusatz = null,
    ) {
    }

    public function summe(): int
    {
        return array_sum($this->werte);
    }
}
