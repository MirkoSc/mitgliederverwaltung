<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

/** Fetches a URL and reports status and response headers of the final answer; swapped for a fake in tests. */
interface HeaderProbe
{
    /**
     * @return array{status: int, headers: array<string, string>}|null header
     *         names lower-case, repeated headers joined with ", "; null when
     *         no answer arrived
     */
    public function __invoke(string $url): ?array;
}
