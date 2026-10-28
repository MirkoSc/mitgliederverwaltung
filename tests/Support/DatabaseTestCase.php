<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests against the real MariaDB of docker compose (or the CI
 * service container), in a database of their own next to the dev one.
 * Taken over from Vereinsbelege.
 *
 * Every test starts on an empty database (all tables dropped). Without a
 * reachable database the tests are skipped - unless TEST_DB_REQUIRED=1 (CI)
 * turns that into a failure, so a broken service container cannot quietly
 * reduce the suite to the unit tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        TestDatenbank::alleTabellenLoeschen(TestDatenbank::verbindenOderAbbrechen());
    }

    protected function pdo(): \PDO
    {
        return TestDatenbank::verbindenOderAbbrechen();
    }

    public static function dbName(): string
    {
        return getenv('TEST_DB_NAME') ?: 'mitgliederverwaltung_test';
    }

    /**
     * Connection details of the test database, as the application's own
     * config.php would provide them.
     *
     * @return array<mixed>
     */
    public static function configData(): array
    {
        return [
            'debug' => false,
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: 'db',
                'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
                'name' => self::dbName(),
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASSWORD') ?: 'dev-root',
            ],
            'server_key' => base64_encode(str_repeat('t', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            'cron_token' => 'test-token',
        ];
    }

    protected function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }
}
