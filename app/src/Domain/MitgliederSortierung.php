<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The columns the member list can be sorted by (a click on the column
 * header, docs/spec/06-oberflaeche.md section 3). The values appear in URLs.
 */
enum MitgliederSortierung: string
{
    case Name = 'name';
    case Nummer = 'nummer';
    case Ort = 'ort';
    case Beitragsart = 'beitragsart';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Name => 'Name',
            self::Nummer => 'Mitgliedsnummer',
            self::Ort => 'Ort',
            self::Beitragsart => 'Beitragsart',
        };
    }
}
