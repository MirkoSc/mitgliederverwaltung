<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One family in the list of the families page (issue #92): name, the
 * payer(s), how many persons, and whether their addresses differ.
 */
final readonly class FamilienZeile
{
    /**
     * @param list<string> $zahler names of the persons with the role Zahler (normally one)
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $anzahl,
        public array $zahler,
        public bool $anschriftenAbweichend,
    ) {
    }

    public function zahlerText(): string
    {
        return $this->zahler === [] ? 'kein Zahler' : implode(', ', $this->zahler);
    }

    public function anzahlText(): string
    {
        return match ($this->anzahl) {
            0 => 'niemand',
            1 => '1 Person',
            default => $this->anzahl . ' Personen',
        };
    }
}
