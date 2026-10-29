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
     * The hints of every current member with at least one hint (list
     * "Auffälligkeiten", 09 section 2). A fixed number of queries, not one per
     * member: the members, families and proofs are read once and grouped here.
     * Former members are left out; the family context still counts them.
     *
     * @return list<MitgliedHinweise> ordered by name, as the member list
     */
    public function fuerAlle(?\DateTimeImmutable $stichtag = null): array
    {
        $tag = $stichtag ?? new \DateTimeImmutable('today');
        $alle = $this->mitglieder->alle(true, $tag);

        $familien = [];
        foreach ($alle as $person) {
            if ($person->familieId !== null) {
                $familien[$person->familieId][] = $person;
            }
        }
        $nachweise = $this->nachweise->fuerMitglieder(
            array_values(array_filter(array_map(static fn(Mitglied $m): ?int => $m->id, $alle))),
        );
        $beitragsarten = $this->beitragsarten->alle();

        $ergebnis = [];
        foreach ($alle as $mitglied) {
            if ($mitglied->id === null || $mitglied->istEhemalig($tag)) {
                continue;
            }
            $kontext = new HinweisKontext(
                $tag,
                $mitglied->familieId === null ? [] : ($familien[$mitglied->familieId] ?? []),
                $nachweise[$mitglied->id] ?? [],
                $beitragsarten,
            );
            $hinweise = Hinweise::fuer($mitglied, $kontext);
            if ($hinweise !== []) {
                $ergebnis[] = new MitgliedHinweise($mitglied, $hinweise);
            }
        }

        return $ergebnis;
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
