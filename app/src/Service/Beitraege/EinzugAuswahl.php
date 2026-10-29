<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

/**
 * Step 1 of the direct debit (docs/spec/08-beitraege.md section 3): the
 * open fees that can be collected (all preselected on the page), beside
 * them the open fees that cannot, and what the club data still lacks for a
 * file at all.
 */
final readonly class EinzugAuswahl
{
    /**
     * @param list<EinzugKandidat> $auswaehlbar by name and year
     * @param list<EinzugKandidat> $nichtAuswaehlbar by name and year
     * @param list<string> $fehlendeVereinsdaten German labels; [] = a file can be made
     */
    public function __construct(
        public array $auswaehlbar,
        public array $nichtAuswaehlbar,
        public array $fehlendeVereinsdaten,
        public \DateTimeImmutable $vorschlagFaelligkeit,
        /** The template for the remittance text in effect (club data or the default). */
        public string $verwendungszweck,
    ) {
    }

    /** "Erzeugen" is blocked only without creditor identifier, IBAN or name (08 section 4). */
    public function moeglich(): bool
    {
        return $this->fehlendeVereinsdaten === [];
    }

    /** Sum of everything that can be collected, integer cent. */
    public function summeCent(): int
    {
        return array_sum(array_map(static fn(EinzugKandidat $k): int => $k->beitrag->betragCent, $this->auswaehlbar));
    }

    /**
     * The fee years among the collectable fees, oldest first.
     *
     * @return list<int>
     */
    public function jahre(): array
    {
        $jahre = array_values(array_unique(array_map(static fn(EinzugKandidat $k): int => $k->beitrag->jahr, $this->auswaehlbar)));
        sort($jahre);

        return $jahre;
    }
}
