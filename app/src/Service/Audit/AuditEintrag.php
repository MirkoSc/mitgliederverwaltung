<?php

declare(strict_types=1);

namespace App\Service\Audit;

/** One row of `audit_log`, as stored. */
final readonly class AuditEintrag
{
    public function __construct(
        public int $id,
        public string $ts,
        public ?int $userId,
        public string $action,
        public ?string $entity,
        public ?string $entityId,
        public ?string $ipHash,
        public string $details,
        public string $prevHash,
        public string $hash,
    ) {
    }

    /** The hash this row must carry, given the row before it. */
    public function sollHash(): string
    {
        return AuditLog::hashBilden(
            $this->prevHash,
            $this->ts,
            $this->userId,
            $this->action,
            $this->entity,
            $this->entityId,
            $this->ipHash,
            $this->details,
        );
    }
}
