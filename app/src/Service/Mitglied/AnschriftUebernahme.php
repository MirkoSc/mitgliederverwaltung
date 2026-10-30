<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

/**
 * What "Anschrift übernehmen" did (FamilienPflege::anschriftUebernehmen()).
 */
final readonly class AnschriftUebernahme
{
    /**
     * @param list<int> $geaendert ids of the persons that got the address (a new version each)
     * @param int $konflikte persons somebody else saved meanwhile - left as they were
     */
    public function __construct(
        public array $geaendert,
        public int $konflikte,
    ) {
    }
}
