<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the member list: what the list shows and nothing else, so a
 * page of 50 does not load 50 complete members.
 */
final readonly class MitgliedZeile
{
    public function __construct(
        public int $id,
        public int $mitgliedsnummer,
        public string $nachname,
        public string $vorname,
        public string $plz,
        public string $ort,
        /** The tick "aktiv"; false = passiv. */
        public bool $aktiv,
        /** Austrittsdatum reached on the day the list was read (07 section 1). */
        public bool $ehemalig,
        /** Name of the fee type, '' without one. */
        public string $beitragsart,
        /** @var list<string> names of the departments, in the order of the settings */
        public array $abteilungen,
    ) {
    }

    /** "Mustermann, Erika" - the sort order of the list. */
    public function name(): string
    {
        return $this->vorname === '' ? $this->nachname : $this->nachname . ', ' . $this->vorname;
    }

    /** "12345 Musterstadt", or what exists of it. */
    public function ortAnzeige(): string
    {
        return trim($this->plz . ' ' . $this->ort);
    }

    /** The word on the label: ehemalig, aktiv or passiv. */
    public function status(): string
    {
        return match (true) {
            $this->ehemalig => 'ehemalig',
            $this->aktiv => 'aktiv',
            default => 'passiv',
        };
    }

    /** The CSS modifier of the label (design system "etikett"). */
    public function statusKlasse(): string
    {
        return match (true) {
            $this->ehemalig => 'etikett-fehler',
            $this->aktiv => 'etikett-ok',
            default => '',
        };
    }
}
