<?php

declare(strict_types=1);

namespace App\Service\Beitraege\Sepa;

/**
 * Everything one pain.008 file says (docs/spec/08-beitraege.md section 4):
 * the collecting club with its account and creditor identifier, the due
 * date and the direct debits. Texts are already in the SEPA character set
 * (SepaText), IBAN/BIC normalised - App\Service\Beitraege\Sepa\Pain008
 * only checks.
 */
final readonly class Pain008Auftrag
{
    /**
     * @param list<Pain008Lastschrift> $lastschriften
     */
    public function __construct(
        public string $msgId,
        public string $pmtInfId,
        public \DateTimeImmutable $erstellt,
        /** Account holder or club name: initiating party and creditor. */
        public string $glaeubiger,
        public string $iban,
        /** '' = not provided (IBAN only). */
        public string $bic,
        /** Gläubiger-Identifikationsnummer, e.g. DE98ZZZ09999999999. */
        public string $glaeubigerId,
        public \DateTimeImmutable $faelligAm,
        public array $lastschriften,
    ) {
    }
}
