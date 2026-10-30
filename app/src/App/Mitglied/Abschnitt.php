<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Support\Erweiterung;

/**
 * One section inside an opened member above its tabs - the hints
 * (docs/spec/07-mitglieder.md, 06 section 2).
 *
 * An issue adds its section as `app/src/App/Mitglied/<Name>Abschnitt.php`,
 * and the member view (MitgliedAnsicht, issue #86) finds it (App\App\Mitglied\
 * MitgliedErweiterungen).
 */
interface Abschnitt extends Erweiterung
{
    /** The template below app/views/. */
    public function template(): string;

    /**
     * What the template gets as variables, for one member. MitgliedAnsicht
     * adds `$einbettung` (the page the member is shown on - build links and
     * forms with it, never with fixed addresses), `$mitgliedId` and
     * `$offenerReiter` (docs/spec/06-oberflaeche.md section 5).
     *
     * @return array<string, mixed>
     */
    public function daten(int $mitgliedId): array;
}
