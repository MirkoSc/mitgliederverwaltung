<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the fees page (docs/spec/08-beitraege.md section 2): the fee
 * and just enough of its member to name it.
 */
final readonly class JahresbeitragZeile
{
    public function __construct(
        public Jahresbeitrag $beitrag,
        public int $mitgliedsnummer,
        public string $nachname,
        public string $vorname,
    ) {
    }

    /** "Mustermann, Erika" - the sort order of the page. */
    public function name(): string
    {
        return $this->vorname === '' ? $this->nachname : $this->nachname . ', ' . $this->vorname;
    }
}
