<?php

declare(strict_types=1);

namespace App\Service\Karte;

/** One current member as the page "Karte" shows it: number, name, address. */
final readonly class KartenMitglied
{
    public function __construct(
        public int $id,
        public int $mitgliedsnummer,
        public string $vorname,
        public string $nachname,
        public Anschrift $anschrift,
        public bool $aktiv,
    ) {
    }

    public function name(): string
    {
        return trim($this->nachname . ', ' . $this->vorname, ', ');
    }
}
