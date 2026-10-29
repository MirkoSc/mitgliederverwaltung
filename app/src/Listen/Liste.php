<?php

declare(strict_types=1);

namespace App\Listen;

use App\Domain\Konto;
use App\Support\Erweiterung;

/**
 * One list of the page "Listen" (docs/spec/09-listen-und-exporte.md):
 * Geburtstage, Ehrungen, Funktionen, Selbst kassieren, Auffälligkeiten,
 * BLSV-Meldung, Alle Mitglieder.
 *
 * An issue adds its list as `app/src/Listen/<Name>Liste.php`, and the page
 * finds it (App\Listen\Listen). A list only says what it shows - its
 * filters, its columns and rows; the page with table or cards, sorting,
 * paging and the export as xlsx or csv are the same for all of them
 * (App\App\ListenController, App\Service\Export\ListenExport).
 */
interface Liste extends Erweiterung
{
    /** One sentence for the selection, saying what the list shows. */
    public function beschreibung(): string;

    /**
     * The few filters of the list, in display order. The values reach
     * ergebnis() already validated (ListenFilter::lesen()).
     *
     * @return list<ListenFilter>
     */
    public function filter(): array;

    /**
     * The columns and rows for these filter values. Filter and order in SQL
     * (09 section 1); the account is for lists that show more to the admin
     * (fee columns of the full export). Rows the account may not see are
     * left out here - the page and the export show exactly what this returns.
     *
     * @param array<string, string|int|bool> $filter by ListenFilter::$name
     */
    public function ergebnis(array $filter, Konto $konto): ListenErgebnis;

    /**
     * The file name of an export without extension, e.g.
     * "geburtstage-2027-03". Never personal data (09 section 1); anything
     * but lowercase letters, digits and hyphens is dropped.
     *
     * @param array<string, string|int|bool> $filter
     */
    public function dateiname(array $filter): string;
}
