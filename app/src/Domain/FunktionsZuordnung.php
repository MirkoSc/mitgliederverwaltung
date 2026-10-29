<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One member holding one function, as the list "Funktionen" shows it
 * (docs/spec/09-listen-und-exporte.md section 2). Read-only projection; the
 * member itself stays in Mitglied.
 */
final readonly class FunktionsZuordnung
{
    public function __construct(
        public int $mitgliedId,
        public string $nachname,
        public string $vorname,
        public int $funktionId,
        public string $funktion,
        public string $lizenznummer,
        /** Department names, comma-separated, "" without a department. */
        public string $abteilungen,
        public string $telefon,
        public string $handy,
        public string $email,
    ) {
    }
}
