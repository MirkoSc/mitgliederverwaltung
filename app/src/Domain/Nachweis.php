<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A proof at a member (docs/spec/07-mitglieder.md section 5): pension,
 * studies, apprenticeship. Not versioned - the proofs are a history of
 * their own. Who noted it and when is set automatically and never changed.
 */
final readonly class Nachweis
{
    public function __construct(
        public int $mitgliedId,
        public NachweisArt $art,
        /** Valid up to and including this day; null = no end. */
        public ?\DateTimeImmutable $gueltigBis = null,
        public string $bemerkung = '',
        /** The encrypted file (file_blob), if any. */
        public ?int $blobId = null,
        /** User id; null when the account is gone. */
        public ?int $vermerktVon = null,
        /** Set by the repository on insert. */
        public ?\DateTimeImmutable $vermerktAm = null,
        public ?int $id = null,
    ) {
    }

    /** Valid on that day: no end date, or the end date not yet passed (the day itself counts). */
    public function istGueltigAm(\DateTimeInterface $stichtag): bool
    {
        return $this->gueltigBis === null || $this->gueltigBis->format('Y-m-d') >= $stichtag->format('Y-m-d');
    }

    /** "Nachweis abgelaufen" (07 section 6): an end date before that day. */
    public function istAbgelaufenAm(\DateTimeInterface $stichtag): bool
    {
        return !$this->istGueltigAm($stichtag);
    }
}
