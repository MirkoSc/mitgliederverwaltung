<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Role of a member in a family (07 section 3). The payer carries the fee
 * type "Familie"; parents and children normally have none.
 *
 * The values are stored in the database and are part of the data format.
 */
enum FamilienRolle: string
{
    case Zahler = 'zahler';
    case Elternteil = 'elternteil';
    case Kind = 'kind';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Zahler => 'Zahler',
            self::Elternteil => 'Elternteil',
            self::Kind => 'Kind',
        };
    }
}
