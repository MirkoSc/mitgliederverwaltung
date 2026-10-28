<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;

/**
 * Settings section System (docs/spec/04-installation-und-update.md
 * sections 5 and 7): the system check and the integrity check. Both load
 * themselves through htmx (app/routes/system.php), so the slow parts - the
 * probe of the own domain, the hashing - never hold the settings page back.
 */
final readonly class SystemAbschnitt implements Abschnitt
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'system';
    }

    public function titel(): string
    {
        return 'System';
    }

    public function reihenfolge(): int
    {
        return 90;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/system';
    }

    public function daten(): array
    {
        return [];
    }
}
