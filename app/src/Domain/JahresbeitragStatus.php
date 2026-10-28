<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of an annual fee (08 section 2):
 * offen -> im_einzug (in a direct debit) -> bezahlt; a returned debit
 * goes from bezahlt back to offen.
 *
 * The values are stored in the database and are part of the data format.
 */
enum JahresbeitragStatus: string
{
    case Offen = 'offen';
    case ImEinzug = 'im_einzug';
    case Bezahlt = 'bezahlt';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Offen => 'offen',
            self::ImEinzug => 'im Einzug',
            self::Bezahlt => 'bezahlt',
        };
    }
}
