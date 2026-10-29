<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

use App\Domain\Mitglied;

/** One current member with the hints that apply to them today. */
final readonly class MitgliedHinweise
{
    /**
     * @param list<Hinweis> $hinweise never empty
     */
    public function __construct(
        public Mitglied $mitglied,
        public array $hinweise,
    ) {
    }
}
