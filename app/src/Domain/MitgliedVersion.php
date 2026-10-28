<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One stored version of a member (docs/spec/07-mitglieder.md section 7):
 * the complete state (Mitglied::daten()), who, when and why.
 *
 * `daten` additionally carries `bezeichnungen` - the names of family, fee
 * type, departments and functions at that moment - so the history stays
 * readable after one of them was renamed or deleted. The names are never
 * compared and never restored; only the ids are.
 */
final readonly class MitgliedVersion
{
    /**
     * @param array<string, mixed> $daten
     */
    public function __construct(
        public int $id,
        public int $mitgliedId,
        public int $nr,
        public VersionsAnlass $anlass,
        /** User id; null for the system or a deleted account. */
        public ?int $geaendertVon,
        public \DateTimeImmutable $geaendertAm,
        public array $daten,
    ) {
    }

    /**
     * The names stored with the version: familie, beitragsart (string|null),
     * abteilungen, funktionen (id => name).
     *
     * @return array<string, mixed>
     */
    public function bezeichnungen(): array
    {
        return is_array($this->daten['bezeichnungen'] ?? null) ? $this->daten['bezeichnungen'] : [];
    }
}
