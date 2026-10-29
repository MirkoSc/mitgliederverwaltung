<?php

declare(strict_types=1);

namespace App\Listen;

/** What a list delivers for one set of filter values. */
final readonly class ListenErgebnis
{
    /**
     * @param list<Spalte> $spalten
     * @param list<ListenZeile> $zeilen in the list's own default order
     * @param list<string> $hinweise shown above the table, never in the export
     *        (e.g. members without a birth date in the BLSV report)
     */
    public function __construct(
        public array $spalten,
        public array $zeilen,
        public array $hinweise = [],
        public ?ListenNavigation $navigation = null,
    ) {
        $schluessel = array_map(static fn(Spalte $s): string => $s->schluessel, $spalten);
        if ($schluessel === [] || count($schluessel) !== count(array_unique($schluessel))) {
            throw new \InvalidArgumentException('A list needs at least one column, and column keys must be unique.');
        }
    }
}
