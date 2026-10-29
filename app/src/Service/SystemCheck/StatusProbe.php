<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

/** Fetches a URL and reports the final HTTP status code; swapped for a fake in tests. */
interface StatusProbe
{
    /**
     * @return int|null the status code, null when no answer arrived
     */
    public function __invoke(string $url): ?int;
}
