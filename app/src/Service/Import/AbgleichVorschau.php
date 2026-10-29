<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * What the merge view shows (docs/spec/10-import.md section 4): the file
 * with its errors and, for a file without errors, the sorting into
 * Neu / Fehlt in der Datei / Geändert. A file with errors is never taken
 * over, so it gets no comparison.
 */
final readonly class AbgleichVorschau
{
    public function __construct(
        public ImportDatei $datei,
        public ?AbgleichVergleich $vergleich,
        public AbgleichFelder $felder,
    ) {
    }

    public function istFehlerfrei(): bool
    {
        return $this->datei->istFehlerfrei();
    }
}
