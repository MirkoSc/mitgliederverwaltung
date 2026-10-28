<?php

declare(strict_types=1);

namespace App\Listen;

use App\Support\Erweiterung;

/**
 * One list of the page "Listen" (docs/spec/09-listen-und-exporte.md):
 * Geburtstage, Ehrungen, Funktionen, Selbst kassieren, Auffälligkeiten,
 * BLSV-Meldung, Alle Mitglieder.
 *
 * An issue adds its list as `app/src/Listen/<Name>Liste.php`, and the page
 * finds it (App\Listen\Listen). The list page with filters and export
 * (issue #24) extends this contract with what a list delivers.
 */
interface Liste extends Erweiterung
{
    /** One sentence for the selection, saying what the list shows. */
    public function beschreibung(): string;
}
