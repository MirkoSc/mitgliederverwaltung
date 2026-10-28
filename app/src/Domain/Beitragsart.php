<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A fee type (docs/spec/08-beitraege.md section 1). A changed amount only
 * affects annual fees created afterwards - they copy it. Deleting is only
 * possible without members, otherwise it is deactivated.
 */
final readonly class Beitragsart
{
    use Kopierbar;

    public function __construct(
        public string $name,
        /** Integer cent, never float. */
        public int $betragCent,
        /** Only drives the hints (07 section 6); null = no rule. */
        public ?BeitragsRegel $regel = null,
        public AktivArt $aktivArt = AktivArt::Egal,
        public int $sortierung = 0,
        public bool $aktiv = true,
        /** Null until saved. */
        public ?int $id = null,
    ) {
    }

    public function hatRegel(BeitragsRegel $regel): bool
    {
        return $this->regel === $regel;
    }
}
