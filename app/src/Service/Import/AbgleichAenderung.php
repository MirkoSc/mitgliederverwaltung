<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Mitglied;

/**
 * A member of the section "Geändert" (docs/spec/10-import.md section 4):
 * in the application and in the file, with different values. Taken over
 * whole or not at all.
 */
final readonly class AbgleichAenderung
{
    /**
     * @param array<string, array{mixed, mixed}> $felder field of Mitglied::daten() => [in the application, in the file]
     */
    public function __construct(
        public Mitglied $vorher,
        public ImportEintrag $eintrag,
        public array $felder,
    ) {
    }
}
