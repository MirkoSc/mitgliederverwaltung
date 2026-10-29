<?php

declare(strict_types=1);

namespace App\Listen;

/**
 * A button in one row of a list that posts to a route of its own (e.g.
 * "geehrt" in the honours list). Only the page shows it - the export carries
 * columns, not buttons. The page adds the CSRF token.
 */
final readonly class ListenAktion
{
    /**
     * @param string $pfad where the form posts to
     * @param array<string, string> $felder hidden fields (ids and numbers, never names)
     * @param bool $zurueckgenommen true for a button that undoes something (shown quieter)
     */
    public function __construct(
        public string $beschriftung,
        public string $pfad,
        public array $felder = [],
        public bool $zurueckgenommen = false,
    ) {
    }
}
