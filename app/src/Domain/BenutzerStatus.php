<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where an account stands (docs/spec/03-datenhaltung.md section 2, `user.status`).
 * Only `aktiv` may log in; an invited account sets its password first, a
 * locked one waits for an admin (docs/spec/02-sicherheit.md section 5).
 *
 * The values are what `user.status` stores, so they are part of the data
 * format.
 */
enum BenutzerStatus: string
{
    case Eingeladen = 'eingeladen';
    case Aktiv = 'aktiv';
    case Gesperrt = 'gesperrt';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Eingeladen => 'eingeladen',
            self::Aktiv => 'aktiv',
            self::Gesperrt => 'gesperrt',
        };
    }
}
