<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\AuditAktion;
use App\Domain\Rolle;
use App\Service\Migration\Migrator;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestDatenbank;

/** The audit page and its settings section. */
final class AuditSeiteTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return static fn(): \PDO => TestDatenbank::verbindenOderAbbrechen();
    }

    protected function setUp(): void
    {
        $pdo = TestDatenbank::verbindenOderAbbrechen();
        TestDatenbank::alleTabellenLoeschen($pdo);
        parent::setUp();
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
