<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Erweiterungen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Listen\Liste;
use App\Listen\ListenErgebnis;
use App\Listen\Spalte;

/** A list as an issue would add it - for ErweiterungenTest. */
final class GeburtstageBeispielListe implements Liste
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'geburtstage';
    }

    public function titel(): string
    {
        return 'Geburtstage';
    }

    public function beschreibung(): string
    {
        return 'Beschreibung von Geburtstage.';
    }

    public function reihenfolge(): int
    {
        return 10;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function filter(): array
    {
        return [];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        return new ListenErgebnis([Spalte::text('name', 'Name')], []);
    }

    public function dateiname(array $filter): string
    {
        return $this->schluessel();
    }
}
