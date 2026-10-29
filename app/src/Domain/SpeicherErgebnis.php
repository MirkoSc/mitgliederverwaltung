<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What saving a member did (App\Repository\MitgliedRepository::speichern()).
 */
final readonly class SpeicherErgebnis
{
    /**
     * @param list<string> $hinweise what a restore had to leave out (UI text)
     */
    public function __construct(
        /** As stored, with id, version and the normalised IBAN. */
        public Mitglied $mitglied,
        /** False when nothing changed - no version was written then. */
        public bool $neueVersion,
        public array $hinweise = [],
    ) {
    }
}
