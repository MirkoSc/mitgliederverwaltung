<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A function in the club (docs/spec/07-mitglieder.md section 4), a tick at
 * the member. Deleting is only possible without members, otherwise it is
 * deactivated.
 */
final readonly class Funktion
{
    public function __construct(
        public string $name,
        /** Whether the member form asks for the licence number (Übungsleiter). */
        public bool $mitLizenz = false,
        public int $sortierung = 0,
        public bool $aktiv = true,
        /** Null until saved. */
        public ?int $id = null,
    ) {
    }
}
