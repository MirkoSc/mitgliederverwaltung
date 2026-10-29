<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * What reading an import.csv gives (docs/spec/10-import.md section 2): the
 * valid lines, every problem found, and the families by key. A file with
 * errors is never imported - not even its valid lines.
 */
final readonly class ImportDatei
{
    /**
     * @param list<ImportEintrag> $eintraege valid lines in file order
     * @param list<ImportFehler> $fehler
     * @param array<string, string> $familien family key => name
     * @param list<string> $unbekannteSpalten columns the format does not know (ignored)
     * @param list<string> $spalten the columns of the format the file has; [] = not known
     */
    public function __construct(
        public array $eintraege,
        public array $fehler,
        public array $familien,
        public array $unbekannteSpalten = [],
        public array $spalten = [],
    ) {
    }

    public function istFehlerfrei(): bool
    {
        return $this->fehler === [];
    }
}
