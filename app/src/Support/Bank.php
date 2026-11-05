<?php

declare(strict_types=1);

namespace App\Support;

/** A German bank from app/data/banken.php (App\Support\Banken). */
final readonly class Bank
{
    public function __construct(
        public string $blz,
        public string $name,
        /** null for the few entries the Bundesbank lists without one. */
        public ?string $bic,
    ) {
    }
}
