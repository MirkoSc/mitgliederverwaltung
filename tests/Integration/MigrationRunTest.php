<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Migration\Migration;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The migrator against a real server (docs/spec/03-datenhaltung.md
 * section 4, "Migrationslauf von 0"). Taken over from Vereinsbelege, plus
 * the case parallel development brings: migrations merged out of order.
 */
final class MigrationRunTest extends DatabaseTestCase
{
    private static function fixtures(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/migrations/' . $name;
    }

    /**
     * @return list<string>
     */
    private function tabellen(): array
    {
        return $this->pdo()
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testRunFromZeroAppliesEveryMigrationInNumericOrder(): void
    {
        $migrator = new Migrator($this->pdo(), self::fixtures('gueltig'));

        self::assertSame(0, $migrator->currentVersion());

        $result = $migrator->migrate();

        self::assertSame([1, 2, 10], array_map(static fn(Migration $m): int => $m->version, $result->applied));
        self::assertSame(0, $result->fromVersion);
        self::assertSame(10, $result->toVersion);
        self::assertSame(['a', 'b', 'c', 'schema_version'], $this->tabellen());
    }

    public function testSecondRunIsIdempotent(): void
    {
        new Migrator($this->pdo(), self::fixtures('gueltig'))->migrate();

        $result = new Migrator($this->pdo(), self::fixtures('gueltig'))->migrate();

        self::assertSame([], $result->applied);
        self::assertSame(10, $result->toVersion);
    }

    /**
     * Parallel issues own number ranges and are merged in any order
     * (docs/spec/05-entwicklung.md section 6): a database that already has
     * 040 must still get 015 when it arrives later.
     */
    public function testAMigrationMergedLaterBelowTheHighestIsStillApplied(): void
    {
        new Migrator($this->pdo(), self::fixtures('luecke-vorher'))->migrate();

        $migrator = new Migrator($this->pdo(), self::fixtures('luecke-nachher'));
        self::assertSame([15], array_map(static fn(Migration $m): int => $m->version, $migrator->pending()));

        $result = $migrator->migrate();

        self::assertSame([15], array_map(static fn(Migration $m): int => $m->version, $result->applied));
        self::assertSame([1, 15, 40], $migrator->applied());
        self::assertContains('m', $this->tabellen());
    }

    /**
     * The timestamp comes from PHP, not NOW(): the DB session may run in UTC
     * while the application convention is Europe/Berlin.
     */
    public function testEachAppliedMigrationIsRecordedWithALocalTimestamp(): void
    {
        $vorher = new \DateTimeImmutable('-1 minute');
        new Migrator($this->pdo(), self::fixtures('gueltig'))->migrate();

        $rows = $this->pdo()->query('SELECT version, applied_at FROM schema_version ORDER BY version')->fetchAll();

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual($vorher, new \DateTimeImmutable((string) $row['applied_at']));
        }
    }

    /**
     * The application's own migrations, as a fresh installation runs them.
     * Not pinned to a list of versions - that would turn every new
     * migration into a test change - but a second run has to be a no-op.
     */
    public function testTheApplicationsOwnMigrationsRunFromZero(): void
    {
        $result = new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        self::assertNotSame([], $result->applied);
        self::assertSame(0, $result->fromVersion);
        self::assertContains('setting', $this->tabellen());
        self::assertContains('job', $this->tabellen());
        self::assertSame([], new Migrator($this->pdo(), $this->migrationsDir())->migrate()->applied);
    }
}
