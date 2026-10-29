<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The two legal texts the association maintains itself (CLAUDE.md section 3,
 * docs/spec/02-sicherheit.md section 6): Impressum and Datenschutz. Public
 * without a login (E-F3), edited by the admin only (admin.einstellungen).
 *
 * The values are part of the URL and of the setting name - do not rename.
 */
enum Rechtstext: string
{
    case Impressum = 'impressum';
    case Datenschutz = 'datenschutz';

    public function titel(): string
    {
        return match ($this) {
            self::Impressum => 'Impressum',
            self::Datenschutz => 'Datenschutz',
        };
    }

    /** The public address, e.g. "/impressum". */
    public function pfad(): string
    {
        return '/' . $this->value;
    }

    /** The name in the `setting` table, e.g. "rechtstext.impressum". */
    public function einstellung(): string
    {
        return 'rechtstext.' . $this->value;
    }
}
