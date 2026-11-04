<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

/**
 * "Verteilung der Beiträge" of one year (08 section 2b), computed by
 * Beitragsverteilung.
 */
final readonly class Verteilung
{
    /**
     * @param list<VerteilungsZeile> $nachArt by fee type, "ohne Beitragsart" last
     * @param list<VerteilungsZeile> $nachAbteilung by department, "ohne Abteilung" last
     */
    public function __construct(
        public int $jahr,
        /** No created fees for the year: projected from the fee types. */
        public bool $hochgerechnet,
        /** Number of fees. */
        public int $anzahl,
        /** Integer cent. */
        public int $summeCent,
        /** People the department rows count - payers and their family members. */
        public int $personen,
        public array $nachArt,
        public array $nachAbteilung,
    ) {
    }

    /** Share of the total in tenths of a percent, rounded half up; 0 without a total. */
    public function promille(int $cent): int
    {
        return $this->summeCent <= 0 ? 0 : intdiv(2000 * $cent + $this->summeCent, 2 * $this->summeCent);
    }

    /** "34,5 %" - with a non-breaking space. */
    public function anteil(int $cent): string
    {
        $promille = $this->promille($cent);

        return intdiv($promille, 10) . ',' . ($promille % 10) . "\u{00A0}%";
    }
}
