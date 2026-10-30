<?php

declare(strict_types=1);

namespace App\Service\Karte;

/** One address with everybody living there - one marker on the map, one row in the text list. */
final readonly class KartenOrt
{
    /**
     * @param list<KartenMitglied> $mitglieder
     */
    public function __construct(
        public Anschrift $anschrift,
        public array $mitglieder,
        public ?float $lat = null,
        public ?float $lon = null,
    ) {
    }
}
