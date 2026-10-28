<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Kind of a proof (07 section 5).
 *
 * The values are stored in the database and are part of the data format.
 */
enum NachweisArt: string
{
    case Rente = 'rente';
    case Studium = 'studium';
    case Ausbildung = 'ausbildung';
    case Sonstiges = 'sonstiges';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Rente => 'Rente',
            self::Studium => 'Studium',
            self::Ausbildung => 'Ausbildung',
            self::Sonstiges => 'Sonstiges',
        };
    }
}
