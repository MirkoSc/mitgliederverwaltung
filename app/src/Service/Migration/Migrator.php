<?php

declare(strict_types=1);

namespace App\Service\Migration;

/**
 * Applies numbered SQL migrations and records them in schema_version
 * (docs/spec/03-datenhaltung.md section 4). Taken over from Vereinsbelege,
 * with one change for parallel development:
 *
 * Pending is every migration whose number is NOT recorded yet - not only
 * the ones above the highest recorded number. Issues work in parallel in
 * fixed number ranges (docs/ARBEITSPLAN.md) and are merged in any order, so
 * 040 may well be applied before 015 arrives; "above the maximum" would skip
 * 015 forever on every database that already had 040. Gaps in the numbering
 * are therefore normal, and the order of application is always ascending.
 *
 * MySQL/MariaDB DDL commits implicitly, so migration files are NOT atomic:
 * the schema_version row is only written after every statement of a file
 * succeeded. Keep each migration small (one logical change) and never edit
 * an old migration.
 *
 * Used by the installer, the updater step chain and bin/migrate.php.
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /** The highest applied version, 0 for an empty database. */
    public function currentVersion(): int
    {
        $this->ensureSchemaVersionTable();
        $version = $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();

        return $version === null ? 0 : (int) $version;
    }

    /**
     * @return list<int> every applied version, ascending
     */
    public function applied(): array
    {
        $this->ensureSchemaVersionTable();

        return array_map(
            intval(...),
            $this->pdo->query('SELECT version FROM schema_version ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    /**
     * @return list<Migration> not yet applied, ascending
     */
    public function pending(): array
    {
        $applied = array_flip($this->applied());

        return array_values(array_filter(
            Migration::discover($this->migrationsDir),
            static fn(Migration $m): bool => !isset($applied[$m->version]),
        ));
    }

    /**
     * @param (\Closure(Migration): void)|null $onProgress called before each migration
     */
    public function migrate(?\Closure $onProgress = null): MigrationResult
    {
        $fromVersion = $this->currentVersion();
        $applied = [];

        foreach ($this->pending() as $migration) {
            if ($onProgress !== null) {
                $onProgress($migration);
            }
            $this->apply($migration);
            $applied[] = $migration;
        }

        return new MigrationResult($applied, $fromVersion, $this->currentVersion());
    }

    private function apply(Migration $migration): void
    {
        $sql = file_get_contents($migration->path);
        if ($sql === false) {
            throw new MigrationException('Cannot read migration file: ' . $migration->path);
        }

        foreach (SqlSplitter::split($sql) as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new MigrationException(sprintf(
                    'Migration %03d_%s failed at statement %d: %s',
                    $migration->version,
                    $migration->name,
                    $index + 1,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        // Timestamp from PHP, not NOW(): the DB session may run in UTC while
        // the application convention is Europe/Berlin (set in the bootstrap).
        $insert = $this->pdo->prepare(
            'INSERT INTO schema_version (version, applied_at) VALUES (?, ?)',
        );
        $insert->execute([
            $migration->version,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }

    private function ensureSchemaVersionTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_version (
                version INT NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
}
