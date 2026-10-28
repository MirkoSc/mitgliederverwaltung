<?php

declare(strict_types=1);

namespace App\Service\Audit;

/** Result of AuditLog::pruefen(). */
final readonly class KettenPruefung
{
    public function __construct(
        public int $geprueft,
        /** Id of the first row where the chain breaks, null = intact. */
        public ?int $bruchBeiId,
        public ?string $grund = null,
    ) {
    }

    public function intakt(): bool
    {
        return $this->bruchBeiId === null;
    }
}
