<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Connection to the test database and its cleanup, shared by
 * DatabaseTestCase and tests that build their own app (AppTestCase).
 */
final class TestDatenbank
{
    private static ?\PDO $pdo = null;
    private static ?string $fehler = null;

    /** Connects without a database name, creates the database if needed and selects it. */
    public static function verbinden(): ?\PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (self::$fehler !== null) {
            return null;
        }

        /** @var array{host: string, port: int, user: string, password: string} $db */
        $db = DatabaseTestCase::configData()['db'];

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
                $db['user'],
                $db['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ],
            );
            $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4', DatabaseTestCase::dbName()));
            $pdo->exec(sprintf('USE `%s`', DatabaseTestCase::dbName()));
        } catch (\PDOException $e) {
            self::$fehler = $e->getMessage();

            return null;
        }

        return self::$pdo = $pdo;
    }

    public static function fehler(): string
    {
        return self::$fehler ?? 'unknown error';
    }

    /** Connection, or fail (TEST_DB_REQUIRED=1) / skip the current test. */
    public static function verbindenOderAbbrechen(): \PDO
    {
        $pdo = self::verbinden();
        if ($pdo === null) {
            if (getenv('TEST_DB_REQUIRED') === '1') {
                throw new \PHPUnit\Framework\AssertionFailedError('Test database not available: ' . self::fehler());
            }
            throw new \PHPUnit\Framework\SkippedWithMessageException('Test database not available: ' . self::fehler());
        }

        return $pdo;
    }

    /** Drops every table, whatever other issues have added. */
    public static function alleTabellenLoeschen(\PDO $pdo): void
    {
        $tables = $pdo
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);

        if ($tables === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', (string) $table));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
