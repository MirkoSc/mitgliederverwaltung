<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Mitglied;

/**
 * One valid line of import.csv: the member as it will be saved (origin
 * `import`, departments, fee type and functions already resolved to ids,
 * family still open) and the key of its family, if it has one.
 */
final readonly class ImportEintrag
{
    public function __construct(
        /** Line in the file, the header being line 1. */
        public int $zeile,
        public Mitglied $mitglied,
        /** `familie` of the line: the FamID of the old software. */
        public ?string $familie,
    ) {
    }
}
