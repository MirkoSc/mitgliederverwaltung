<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Erweiterungen;

use App\Admin\Einstellungen\Abschnitt;
use App\Dienste;
use App\Domain\Permission;

/** A settings section as an issue would add it - for ErweiterungenTest. */
final class BeispielAbschnitt implements Abschnitt
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'beispiel';
    }

    public function titel(): string
    {
        return 'Beispiel';
    }

    public function reihenfolge(): int
    {
        return 20;
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
        return ['title' => 'Beispiel', 'text' => 'Inhalt des Beispiel-Abschnitts.'];
    }
}
