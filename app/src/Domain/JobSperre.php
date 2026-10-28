<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The lock one request holds on a step chain (App\Repository\JobRepository::
 * sperren()). Whoever writes the result of a step shows it: a step whose
 * lock has run out meanwhile - and been taken by the next request - can no
 * longer overwrite that request's position.
 */
final readonly class JobSperre
{
    public function __construct(
        public int $jobId,
        /** `job.locked_until` as this lock set it; doubles as its token. */
        public string $bis,
    ) {
    }
}
