<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a member pays (07 section 1): by direct debit or by themselves -
 * the latter appear in the list "Selbst kassieren" (09).
 *
 * The values are stored in the database and are part of the data format.
 */
enum Zahlart: string
{
    case Lastschrift = 'lastschrift';
    case Selbst = 'selbst';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Lastschrift => 'Lastschrift',
            self::Selbst => 'Selbstzahler',
        };
    }
}
