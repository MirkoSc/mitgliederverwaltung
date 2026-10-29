<?php

declare(strict_types=1);

namespace App\Listen;

/** One row of a list. */
final readonly class ListenZeile
{
    /**
     * @param array<string, string|int|\DateTimeInterface|null> $werte by Spalte::$schluessel;
     *        a missing key is an empty cell
     * @param int|null $mitgliedId the row opens this member (id, never a name - CLAUDE.md section 4)
     * @param string|null $marke short label shown beside the first cell in the view
     *        only (e.g. "rund"); a column is what the export carries
     * @param ListenAktion|null $aktion a button for this row, shown by the page only
     */
    public function __construct(
        public array $werte,
        public ?int $mitgliedId = null,
        public ?string $marke = null,
        public ?ListenAktion $aktion = null,
    ) {
    }
}
