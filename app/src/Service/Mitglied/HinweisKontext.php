<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

use App\Domain\Beitragsart;
use App\Domain\Mitglied;
use App\Domain\Nachweis;

/**
 * What Hinweise::fuer() needs besides the member itself (07 section 6):
 * the day, the family, the proofs and the fee types.
 */
final readonly class HinweisKontext
{
    /** @var array<int, Beitragsart> by id */
    public array $beitragsarten;

    /**
     * @param list<Mitglied> $familie all persons of the member's family as stored (the
     *        member included or not - Hinweise::fuer() puts the member it is given in
     *        its place); empty without a family
     * @param list<Nachweis> $nachweise the proofs of the member
     * @param list<Beitragsart> $beitragsarten all fee types, inactive ones too
     */
    public function __construct(
        public \DateTimeImmutable $stichtag,
        public array $familie = [],
        public array $nachweise = [],
        array $beitragsarten = [],
    ) {
        $nachId = [];
        foreach ($beitragsarten as $art) {
            if ($art->id !== null) {
                $nachId[$art->id] = $art;
            }
        }
        $this->beitragsarten = $nachId;
    }
}
