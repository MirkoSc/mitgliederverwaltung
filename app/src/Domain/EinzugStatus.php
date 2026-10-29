<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a direct debit (08 section 3); a confirmed one is closed.
 *
 * The values are stored in the database and are part of the data format.
 */
enum EinzugStatus: string
{
    case Erzeugt = 'erzeugt';
    case Bestaetigt = 'bestaetigt';
    case Verworfen = 'verworfen';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Erzeugt => 'erzeugt',
            self::Bestaetigt => 'bestätigt',
            self::Verworfen => 'verworfen',
        };
    }
}
