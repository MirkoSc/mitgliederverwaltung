<?php

declare(strict_types=1);

namespace App\Service\Beitraege\Sepa;

/**
 * One direct debit of a pain.008 file (docs/spec/08-beitraege.md section 4):
 * the account holder with IBAN and optional BIC, the amount in integer cents
 * (EUR), the mandate and the unstructured remittance text.
 */
final readonly class Pain008Lastschrift
{
    public function __construct(
        public string $endToEndId,
        public int $cent,
        public string $mandatsreferenz,
        public \DateTimeImmutable $mandatsdatum,
        public string $name,
        public string $iban,
        /** '' = not provided (IBAN only). */
        public string $bic,
        public string $verwendungszweck,
    ) {
    }
}
