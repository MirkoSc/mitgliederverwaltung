<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Gender of a member (docs/spec/07-mitglieder.md section 1); the values are
 * what `mitglied.geschlecht` stores and what the BLSV export needs.
 *
 * The values are stored in the database and are part of the data format.
 */
enum Geschlecht: string
{
    case M = 'm';
    case W = 'w';
    case D = 'd';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::M => 'männlich',
            self::W => 'weiblich',
            self::D => 'divers',
        };
    }
}
