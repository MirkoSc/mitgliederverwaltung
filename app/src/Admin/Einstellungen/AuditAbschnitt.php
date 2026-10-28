<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;

/**
 * The audit log in the settings, part of "System" (docs/spec/02-sicherheit.md
 * section 8). Only a pointer: the list and the chain check are a page of
 * their own (/admin/audit), so the settings page stays light and needs no
 * database for it.
 */
final class AuditAbschnitt implements Abschnitt
{
    public static function erzeugen(Dienste $dienste): self
    {
        return new self();
    }

    public function schluessel(): string
    {
        return 'audit';
    }

    public function titel(): string
    {
        return 'System: Audit-Log';
    }

    public function reihenfolge(): int
    {
        return 980;
    }

    public function recht(): Permission
    {
        return Permission::AdminAudit;
    }

    public function template(): string
    {
        return 'admin/einstellungen/audit';
    }

    public function daten(): array
    {
        return [];
    }
}
