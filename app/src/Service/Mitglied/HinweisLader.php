<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

use App\Domain\Mitglied;
use App\Repository\BeitragsartRepository;
use App\Repository\MitgliedRepository;
use App\Repository\NachweisRepository;

/**
 * Reads the context of one stored member (family, proofs, fee types) and
 * asks Hinweise::fuer() - for the member page and for the message after a
 * save. The rules themselves live in Hinweise and read nothing.
 */
final readonly class HinweisLader
{
    public function __construct(
        private MitgliedRepository $mitglieder,
        private NachweisRepository $nachweise,
        private BeitragsartRepository $beitragsarten,
    ) {
    }

    public static function aus(\PDO $pdo): self
    {
        return new self(new MitgliedRepository($pdo), new NachweisRepository($pdo), new BeitragsartRepository($pdo));
    }

    /**
     * @return list<Hinweis>
     */
    public function fuer(Mitglied $mitglied, ?\DateTimeImmutable $stichtag = null): array
    {
        $kontext = new HinweisKontext(
            $stichtag ?? new \DateTimeImmutable('today'),
            $mitglied->familieId === null ? [] : $this->mitglieder->derFamilie($mitglied->familieId),
            $mitglied->id === null ? [] : $this->nachweise->fuerMitglied($mitglied->id),
            $this->beitragsarten->alle(),
        );

        return Hinweise::fuer($mitglied, $kontext);
    }
}
