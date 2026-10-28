<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A family (docs/spec/07-mitglieder.md section 3): a name and the members
 * pointing to it with their role (`mitglied.familie_id`/`familie_rolle`).
 * A family without members is deleted automatically
 * (App\Repository\MitgliedRepository).
 */
final readonly class Familie
{
    public function __construct(
        public int $id,
        public string $name,
        /** FamID of the old software (docs/spec/10-import.md); null if created here. */
        public ?string $importSchluessel,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
