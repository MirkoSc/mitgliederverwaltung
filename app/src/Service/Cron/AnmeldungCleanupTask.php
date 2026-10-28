<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Repository\AuthTokenRepository;
use App\Service\RateLimiter;

/**
 * Sweeps what the login leaves behind: rate limit counters whose window is
 * long over and one-time links that ran out or were used a day ago. A
 * counter or link nobody reads is a hashed address kept for nothing.
 */
final readonly class AnmeldungCleanupTask implements CronTask
{
    public function __construct(
        private RateLimiter $limits,
        private AuthTokenRepository $tokens,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(RateLimiter::aus($dienste), new AuthTokenRepository($dienste->pdo()));
    }

    public function name(): string
    {
        return 'anmeldung_aufraeumen';
    }

    public function istAufraeumen(): bool
    {
        return true;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->limits->cleanup($now) + $this->tokens->deleteExpired($now->modify('-1 day'));
    }
}
