<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;

/**
 * One open annual fee on the page "Einzug zusammenstellen"
 * (docs/spec/08-beitraege.md section 3) with its member: either it can go
 * into a direct debit, or the reason why not.
 */
final readonly class EinzugKandidat
{
    public function __construct(
        public Jahresbeitrag $beitrag,
        public Mitglied $mitglied,
        /** Why it cannot go into a direct debit; null = it can. German, for the page. */
        public ?string $grund = null,
        /** Collected by hand instead - the list "Selbst kassieren" (09): Zahlart `selbst` or no valid IBAN. */
        public bool $selbstKassieren = false,
    ) {
    }

    public function auswaehlbar(): bool
    {
        return $this->grund === null;
    }

    /** "Mustermann, Erika" - the sort order of the fee pages. */
    public function name(): string
    {
        return $this->mitglied->vorname === '' ? $this->mitglied->nachname : $this->mitglied->nachname . ', ' . $this->mitglied->vorname;
    }
}
