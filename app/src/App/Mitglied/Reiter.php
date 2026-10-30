<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Support\Erweiterung;

/**
 * One tab of an opened member (docs/spec/06-oberflaeche.md section 2):
 * Daten, Familie, Beiträge (admin), Nachweise, Verlauf.
 *
 * An issue adds its tab as `app/src/App/Mitglied/<Name>Reiter.php`, and the
 * member view (MitgliedAnsicht, issue #86) finds it
 * (App\App\Mitglied\MitgliedErweiterungen).
 */
interface Reiter extends Erweiterung
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
