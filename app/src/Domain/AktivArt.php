<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether a fee type fits active or passive members, or both
 * (08 section 1) - a mismatch with the member's tick is a hint.
 *
 * The values are stored in the database and are part of the data format.
 */
enum AktivArt: string
{
    case Aktiv = 'aktiv';
    case Passiv = 'passiv';
    case Egal = 'egal';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Aktiv => 'aktiv',
            self::Passiv => 'passiv',
            self::Egal => 'egal',
        };
    }
}
