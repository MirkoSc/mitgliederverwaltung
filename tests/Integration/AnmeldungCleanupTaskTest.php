<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\AuthTokenRepository;
use App\Repository\RateLimitRepository;
use App\Service\Account\EinmalLink;
use App\Service\Cron\AnmeldungCleanupTask;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/** The cron sweep after the login: old counters and dead links go, live ones stay. */
final class AnmeldungCleanupTaskTest extends DatabaseTestCase
{
    public function testOldCountersAndDeadLinksGoLiveOnesStay(): void
    {
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $jetzt = new \DateTimeImmutable('2026-03-02 12:00:00');
        $limits = new RateLimiter(new RateLimitRepository($this->pdo()));
        $tokens = new AuthTokenRepository($this->pdo());
        $id = Benutzerdaten::anlegen($this->pdo());

        $limits->versuch('login.ip', 'alt', 5, $jetzt->modify('-2 hours'));
        $limits->versuch('login.ip', 'neu', 5, $jetzt);
        $tokens->insert($id, 'reset', EinmalLink::hash(EinmalLink::neu()), $jetzt->modify('-2 days'), $jetzt->modify('-2 days'));
        $lebt = $tokens->insert($id, 'invite', EinmalLink::hash(EinmalLink::neu()), $jetzt->modify('+1 day'), $jetzt);

        $entfernt = new AnmeldungCleanupTask($limits, $tokens)->run($jetzt);

        self::assertSame(2, $entfernt);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
        self::assertSame([$lebt], array_map('intval', $this->pdo()->query('SELECT id FROM auth_token')->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
