<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Support\Erweiterung;

/**
 * One section of the settings page (docs/spec/06-oberflaeche.md section 2):
 * Verein, Abteilungen, Beitragsarten, Funktionen, runde Geburtstage,
 * Ehrungsstufen, Benutzer, Import, System.
 *
 * An issue adds its section as `app/src/Admin/Einstellungen/<Name>Abschnitt.php`
 * plus its template, and the page finds it (App\Admin\Einstellungen\
 * Abschnitte). The section only renders; its forms post to routes of the
 * issue's own route file under app/routes/.
 */
interface Abschnitt extends Erweiterung
{
    /** The template below app/views/, e.g. "admin/einstellungen/verein". */
    public function template(): string;

    /**
     * What the template gets as variables.
     *
     * @return array<string, mixed>
     */
    public function daten(): array;
}
