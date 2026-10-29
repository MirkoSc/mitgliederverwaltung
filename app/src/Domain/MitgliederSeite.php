<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One page of the member list with what the pager needs.
 */
final readonly class MitgliederSeite
{
    /**
     * @param list<MitgliedZeile> $zeilen
     * @param int $gesamt members matching the filter, all pages together
     * @param int $seite the page shown (1 based, never beyond the last)
     * @param int $proSeite members per page; 0 = all on one page
     */
    public function __construct(
        public array $zeilen,
        public int $gesamt,
        public int $seite,
        public int $proSeite = MitgliederFilter::SEITENGROESSE,
    ) {
    }

    public function seiten(): int
    {
        return $this->proSeite === 0 ? 1 : max(1, (int) ceil($this->gesamt / $this->proSeite));
    }

    public function hatVorherige(): bool
    {
        return $this->seite > 1;
    }

    public function hatNaechste(): bool
    {
        return $this->seite < $this->seiten();
    }

    /** The sentence for the screen reader after a search: how many were found. */
    public function stand(): string
    {
        return match ($this->gesamt) {
            0 => 'Kein Mitglied gefunden.',
            1 => '1 Mitglied gefunden.',
            default => $this->gesamt . ' Mitglieder gefunden.',
        };
    }
}
