<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One step chain (`job`, docs/spec/01-plattform.md section 4) as
 * App\Repository\JobRepository reads it. `state` is the resumable position
 * of the chain - ids, offsets, counters, never member data.
 */
final readonly class Job
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public int $id,
        public string $typ,
        public JobStatus $status,
        public ?int $userId,
        public array $state,
        /** 0 to 100. */
        public int $progress,
        /** Exception class of the last failure, never its message. */
        public ?string $lastError,
        public ?\DateTimeImmutable $lockedUntil,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
