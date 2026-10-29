<?php

declare(strict_types=1);

namespace App\Domain;

/** An association the club belongs to (docs/spec/11-vereinsdaten.md section 1). */
final readonly class Verband
{
    public function __construct(
        public int $id,
        public string $name,
        public string $vereinsnummer,
        public string $bemerkung,
        public int $sortierung,
    ) {
    }
}
