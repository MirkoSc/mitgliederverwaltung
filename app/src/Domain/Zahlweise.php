<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How an annual fee was paid by hand (08 section 2). A fee paid through a
 * direct debit has none - its `einzug_id` says how.
 *
 * The values are stored in the database and are part of the data format.
 */
enum Zahlweise: string
{
    case Bar = 'bar';
    case Ueberweisung = 'ueberweisung';
    case Sonstiges = 'sonstiges';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Bar => 'bar',
            self::Ueberweisung => 'Überweisung',
            self::Sonstiges => 'sonstiges',
        };
    }
}
