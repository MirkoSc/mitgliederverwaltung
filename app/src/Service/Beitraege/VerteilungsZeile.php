<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

/**
 * One row of "Verteilung der Beiträge" (08 section 2b): a fee type with its
 * number of fees, or a department with its number of people.
 */
final readonly class VerteilungsZeile
{
    public function __construct(
        public string $name,
        public int $anzahl,
        /** Integer cent. */
        public int $summeCent,
        /** The row "ohne Beitragsart" / "ohne Abteilung". */
        public bool $ohne = false,
    ) {
    }
}
