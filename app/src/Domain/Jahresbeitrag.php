<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The fee of one member for one year (docs/spec/08-beitraege.md section 2),
 * unique per member and year. The amount is a copy taken when it was
 * created; it may only be changed while the status is `offen`.
 */
final readonly class Jahresbeitrag
{
    /**
     * @param list<Vermerk> $vermerke oldest first
     */
    public function __construct(
        public int $mitgliedId,
        public int $jahr,
        /** Integer cent. */
        public int $betragCent,
        public JahresbeitragStatus $status = JahresbeitragStatus::Offen,
        public ?int $einzugId = null,
        /** How it was paid by hand; null when paid by direct debit or not paid. */
        public ?Zahlweise $zahlweise = null,
        public ?\DateTimeImmutable $bezahltAm = null,
        public array $vermerke = [],
        public ?int $id = null,
    ) {
    }

    public function betragAenderbar(): bool
    {
        return $this->status === JahresbeitragStatus::Offen;
    }

    public function mitVermerk(Vermerk $vermerk): self
    {
        return clone($this, ['vermerke' => [...$this->vermerke, $vermerk]]);
    }
}
