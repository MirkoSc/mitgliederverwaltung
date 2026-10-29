<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The rule of a fee type (docs/spec/08-beitraege.md section 1). It only
 * drives the hints (07 section 6) and never blocks anything; a fee type
 * without a rule stores NULL.
 *
 * The values are stored in the database and are part of the data format.
 */
enum BeitragsRegel: string
{
    case Jugend = 'jugend';
    case Familie = 'familie';
    case Rentner = 'rentner';
    case Beitragsfrei = 'beitragsfrei';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Jugend => 'Jugend',
            self::Familie => 'Familie',
            self::Rentner => 'Rentner',
            self::Beitragsfrei => 'Beitragsfrei',
        };
    }
}
