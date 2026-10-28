<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\AuditAktion;
use App\Domain\Rolle;
use App\Service\Migration\Migrator;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\DatabaseTestCase;

/** The audit page and its settings section. */
final class AuditSeiteTest extends AppTestCase
{
    private static ?\PDO $pdo = null;

    protected function pdoFabrik(): ?\Closure
    {
        self::$pdo ??= new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('TEST_DB_HOST') ?: 'db', (int) (getenv('TEST_DB_PORT') ?: 3306), getenv('TEST_DB_NAME') ?: 'mitgliederverwaltung_test'),
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: 'dev-root',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        return static fn(): \PDO => self::$pdo;
    }

    protected function setUp(): void
    {
        try {
            $this->pdoFabrik()();
        } catch (\PDOException $e) {
            self::markTestSkipped('Test database not available.');
        }
        parent::setUp();
        $pdo = $this->pdoFabrik()();
        $pdo->exec('DROP TABLE IF EXISTS audit_log, job, setting, schema_version');
        new Migrator($pdo, dirname(__DIR__, 2) . '/migrations')->migrate();
    }

    public function testAdminSeesChainOkAndEntries(): void
    {
        $this->dienste->audit()->protokollieren(AuditAktion::BackupErstellt, 1);
        $this->als(Rolle::Admin);
        $antwort = $this->get('/admin/audit');
        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Kette in Ordnung', $antwort->body);
        self::assertStringContainsString('Backup erstellt', $antwort->body);
    }

    public function testManipulationIsShown(): void
    {
        $this->dienste->audit()->protokollieren(AuditAktion::BackupErstellt, 1);
        $this->dienste->audit()->protokollieren(AuditAktion::ExportErzeugt, 1);
        $this->dienste->pdo()->exec('UPDATE audit_log SET user_id = 7 WHERE id = 1');
        $this->als(Rolle::Admin);
        self::assertStringContainsString('Kette unterbrochen bei Eintrag 1', $this->get('/admin/audit')->body);
    }

    public function testBoardCannotOpenIt(): void
    {
        $this->als(Rolle::Vorstand);
        self::assertSame(403, $this->get('/admin/audit')->status);
    }
}
