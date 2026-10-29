<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\RateLimitRepository;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\DatabaseTestCase;

/**
 * The brute force counter of docs/spec/02-sicherheit.md section 3: 15-minute
 * window, counted atomically BEFORE the check. Taken over from
 * Vereinsbelege.
 *
 * Every test pins its own "now": a fixed window is exactly the kind of thing
 * that passes at 10:05 and fails at 10:59:59 if the clock is left to itself.
 */
final class RateLimiterTest extends DatabaseTestCase
{
    private RateLimiter $limits;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->limits = new RateLimiter(new RateLimitRepository($this->pdo()));
    }

    public function testExactlyTheLimitGetsThrough(): void
    {
        $zugelassen = 0;
        for ($i = 0; $i < 10; $i++) {
            $zugelassen += $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:00:00')) ? 1 : 0;
        }

        self::assertSame(3, $zugelassen);
    }

    /**
     * The interleaving of parallel requests on separate connections: all of
     * them ask before any of them knows whether its guess was right. A
     * read-then-write would let every one through; versuch() lets exactly
     * the limit through.
     */
    public function testParallelConnectionsDoNotOvershootTheLimit(): void
    {
        $limiter = [$this->limits];
        for ($i = 0; $i < 4; $i++) {
            $limiter[] = new RateLimiter(new RateLimitRepository($this->zweiteVerbindung()));
        }

        $zugelassen = 0;
        foreach ($limiter as $l) {
            $zugelassen += $l->versuch('login.konto', 'erika@example.org', 3, self::zeit('10:00:00')) ? 1 : 0;
        }

        self::assertSame(3, $zugelassen);
    }

    public function testTheNextWindowStartsFromOne(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:00:00'));
        }

        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:00')));
        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:01')));
        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:02')));
        self::assertFalse($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:03')));
    }

    /** A request with a slightly older clock does not roll a newer window back. */
    public function testAnOlderClockDoesNotResetANewerWindow(): void
    {
        $this->limits->versuch('login.ip', '198.51.100.7', 2, self::zeit('10:15:00'));
        $this->limits->versuch('login.ip', '198.51.100.7', 2, self::zeit('10:14:59'));

        self::assertFalse($this->limits->versuch('login.ip', '198.51.100.7', 2, self::zeit('10:15:01')));
    }

    public function testASuccessClearsTheCounter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:00:00'));
        }

        $this->limits->reset('login.ip', '198.51.100.7');

        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:00:00')));
    }

    /** Per IP and per account in one table (02 section 3). */
    public function testPurposesAndValuesDoNotShareACounter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limits->versuch('login.ip', 'gleich', 3, self::zeit('10:00:00'));
        }

        self::assertFalse($this->limits->versuch('login.ip', 'gleich', 3, self::zeit('10:00:00')));
        self::assertTrue($this->limits->versuch('login.konto', 'gleich', 3, self::zeit('10:00:00')));
        self::assertTrue($this->limits->versuch('login.ip', 'anders', 3, self::zeit('10:00:00')));
    }

    /** Neither an IP address nor an e-mail address reaches the table. */
    public function testTheKeyIsStoredAsADigestOnly(): void
    {
        $this->limits->versuch('login.konto', 'erika@example.org', 3, self::zeit('10:00:00'));

        $gespeichert = (string) $this->pdo()->query('SELECT key_hash FROM rate_limit')->fetchColumn();

        self::assertSame(32, strlen($gespeichert));
        self::assertStringNotContainsString('erika', $gespeichert);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    /** No address (command line) must not mean one counter shared by everybody. */
    public function testAnEmptyValueNeitherCountsNorBlocks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->limits->versuch('login.ip', '', 1, self::zeit('10:00:00')));
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    public function testCleanupRemovesRowsThatCanNoLongerBlockAnybody(): void
    {
        $this->limits->versuch('login.ip', 'alt', 3, self::zeit('10:00:00'));
        $this->limits->versuch('login.ip', 'neu', 3, self::zeit('11:00:00'));

        self::assertSame(1, $this->limits->cleanup(self::zeit('11:00:00')));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    private static function zeit(string $uhrzeit): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-02 ' . $uhrzeit);
    }

    private function zweiteVerbindung(): \PDO
    {
        $db = self::configData()['db'];
        assert(is_array($db));

        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            (string) $db['user'],
            (string) $db['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        );
    }
}
