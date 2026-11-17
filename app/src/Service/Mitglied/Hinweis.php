<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

use App\Domain\Konto;
use App\Domain\Permission;

/**
 * One hint about a member (docs/spec/07-mitglieder.md section 6): computed,
 * never stored, never blocking. The text says what looks wrong and what to
 * do; it holds no personal data (the member is shown beside it).
 */
final readonly class Hinweis
{
    public function __construct(
        public HinweisArt $art,
        public string $text,
    ) {
    }

    /**
     * The hint for an account without bankdaten.sehen ("Nur lesen"): a hint
     * about the bank details says only its kind - its text may name the
     * BIC of the member's bank (02 section 6). Every other hint unchanged.
     */
    public function ohneBankdaten(): self
    {
        return $this->art->betrifftBankdaten() ? new self($this->art, $this->art->bezeichnung() . '.') : $this;
    }

    /**
     * $hinweise as $konto may see them (ohneBankdaten() without bankdaten.sehen).
     *
     * @param list<self> $hinweise
     * @return list<self>
     */
    public static function fuer(array $hinweise, ?Konto $konto): array
    {
        return $konto !== null && $konto->darf(Permission::BankdatenSehen)
            ? $hinweise
            : array_map(static fn(self $h): self => $h->ohneBankdaten(), $hinweise);
    }
}
