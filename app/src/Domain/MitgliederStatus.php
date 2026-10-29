<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The filter "aktiv/passiv" of the member list (docs/spec/06-oberflaeche.md
 * section 2) - the tick "aktiv" of a member, not "ehemalig".
 */
enum MitgliederStatus: string
{
    case Alle = 'alle';
    case Aktiv = 'aktiv';
    case Passiv = 'passiv';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Alle => 'Aktive und passive',
            self::Aktiv => 'Nur aktive',
            self::Passiv => 'Nur passive',
        };
    }
}
