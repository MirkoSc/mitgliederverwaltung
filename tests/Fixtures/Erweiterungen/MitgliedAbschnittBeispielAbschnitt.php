<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Erweiterungen;

use App\App\Mitglied\Abschnitt;
use App\Dienste;
use App\Domain\Permission;

/** A member Abschnitt as an issue would add it - for ErweiterungenTest. */
final class MitgliedAbschnittBeispielAbschnitt implements Abschnitt
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'beispiel-abschnitt';
    }

    public function titel(): string
    {
        return 'Beispiel';
    }

    public function reihenfolge(): int
    {
        return 1;
    }

    public function recht(): Permission
    {
        return Permission::MitgliederSehen;
    }

    public function template(): string
    {
        return 'platzhalter';
    }

    public function daten(int $mitgliedId): array
    {
        return ['mitglied' => $mitgliedId];
    }
}
