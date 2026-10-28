<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Support\Erweiterung;

/**
 * One section inside an opened member - hints at the top, family, proofs,
 * honours (docs/spec/07-mitglieder.md, 06 section 2).
 *
 * An issue adds its section as `app/src/App/Mitglied/<Name>Abschnitt.php`,
 * and the member page (issue #19) finds it (App\App\Mitglied\
 * MitgliedErweiterungen).
 */
interface Abschnitt extends Erweiterung
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
