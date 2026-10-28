<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Erweiterungen;

use App\Admin\Einstellungen\Abschnitt;
use App\Dienste;
use App\Domain\Permission;

/** A settings section as an issue would add it - for ErweiterungenTest. */
final class VereinBeispielAbschnitt implements Abschnitt
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'verein';
    }

    public function titel(): string
    {
        return 'Verein';
    }

    public function reihenfolge(): int
    {
        return 10;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'platzhalter';
    }

    public function daten(): array
    {
        return ['title' => 'Verein', 'text' => 'Inhalt des Vereins-Abschnitts.'];
    }
}
