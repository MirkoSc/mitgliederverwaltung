<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * One problem the preview reports (docs/spec/10-import.md section 1): a line
 * of import.csv that cannot be imported, or the file as a whole (line 0).
 * The text names the column and the wrong value's kind, never the member.
 */
final readonly class ImportFehler
{
    public function __construct(
        /** Line in the file, the header being line 1; 0 for the file as a whole. */
        public int $zeile,
        /** The member number of the line, when it could be read. */
        public ?int $nummer,
        public string $text,
    ) {
    }
}
