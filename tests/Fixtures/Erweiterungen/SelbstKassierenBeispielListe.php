<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Erweiterungen;

use App\Dienste;
use App\Domain\Permission;
use App\Listen\Liste;

/** A list as an issue would add it - for ErweiterungenTest. */
final class SelbstKassierenBeispielListe implements Liste
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'selbst-kassieren';
    }

    public function titel(): string
    {
        return 'Selbst kassieren';
    }

    public function beschreibung(): string
    {
        return 'Beschreibung von Selbst kassieren.';
    }

    public function reihenfolge(): int
    {
        return 40;
    }

    public function recht(): Permission
    {
        return Permission::BeitraegeVerwalten;
    }
}
