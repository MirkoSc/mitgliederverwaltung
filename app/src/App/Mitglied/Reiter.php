<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Support\Erweiterung;

/**
 * One tab of an opened member (docs/spec/06-oberflaeche.md section 2):
 * Daten, Beiträge (admin), Verlauf.
 *
 * An issue adds its tab as `app/src/App/Mitglied/<Name>Reiter.php`, and the
 * member page (issue #19) finds it (App\App\Mitglied\MitgliedErweiterungen).
 */
interface Reiter extends Erweiterung
{
    /** The template below app/views/. */
    public function template(): string;

    /**
     * What the template gets as variables, for one member.
     *
     * @return array<string, mixed>
     */
    public function daten(int $mitgliedId): array;
}
