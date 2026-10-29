<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a member came from (docs/spec/10-import.md): only imported
 * members can be "missing in the file" during a reconciliation.
 *
 * The values are stored in the database and are part of the data format.
 */
enum Herkunft: string
{
    case Import = 'import';
    case Manuell = 'manuell';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Import => 'Import',
            self::Manuell => 'von Hand angelegt',
        };
    }
}
