<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAktion;
use App\Service\Audit\AuditLog;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/** Audit log and its hash chain (docs/spec/02-sicherheit.md section 8). */
final class AuditLogTest extends DatabaseTestCase
{
    private AuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->audit = new AuditLog($this->pdo(), str_repeat('k', 32));
    }

    private function fuelle(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->audit->protokollieren(AuditAktion::AnmeldungErfolgreich, $i, 'user', $i, ['n' => $i], '203.0.113.' . $i);
        }
    }

    public function testEmptyAndFilledChainsAreIntact(): void
    {
        self::assertTrue($this->audit->pruefen()->intakt());
        $this->fuelle(5);
        $pruefung = $this->audit->pruefen();
        self::assertTrue($pruefung->intakt());
        self::assertSame(5, $pruefung->geprueft);
        self::assertSame(5, $this->audit->anzahl());
    }

    public function testFirstRowStartsAtTheGenesisHashAndRowsAreChained(): void
    {
        $this->fuelle(2);
        $rows = $this->pdo()->query('SELECT prev_hash, hash FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(AuditLog::ANFANG, $rows[0]['prev_hash']);
        self::assertSame($rows[0]['hash'], $rows[1]['prev_hash']);
    }

    public function testIpIsStoredOnlyAsKeyedHash(): void
    {
        $this->audit->protokollieren(AuditAktion::Abmeldung, 1, ip: '203.0.113.7');
        $this->audit->protokollieren(AuditAktion::Abmeldung, 1);
        $rows = $this->pdo()->query('SELECT ip_hash FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(hash_hmac('sha256', '203.0.113.7', str_repeat('k', 32)), $rows[0]);
        self::assertNull($rows[1]);
        self::assertStringNotContainsString('203.0.113.7', json_encode($this->audit->neueste(), JSON_THROW_ON_ERROR));
    }

    public function testChangedRowIsDetected(): void
    {
        $this->fuelle(4);
        $this->pdo()->exec("UPDATE audit_log SET user_id = 99 WHERE id = 3");
        $pruefung = $this->audit->pruefen();
        self::assertFalse($pruefung->intakt());
        self::assertSame(3, $pruefung->bruchBeiId);
    }

    public function testChangedDetailsAreDetected(): void
    {
        $this->fuelle(3);
        $this->pdo()->exec("UPDATE audit_log SET details = '{\"n\":42}' WHERE id = 2");
        self::assertSame(2, $this->audit->pruefen()->bruchBeiId);
    }

    public function testRemovedRowInTheMiddleIsDetected(): void
    {
        $this->fuelle(4);
        $this->pdo()->exec('DELETE FROM audit_log WHERE id = 2');
        self::assertSame(3, $this->audit->pruefen()->bruchBeiId);
    }

    public function testRemovedFirstRowIsDetected(): void
    {
        $this->fuelle(3);
        $this->pdo()->exec('DELETE FROM audit_log WHERE id = 1');
        self::assertSame(2, $this->audit->pruefen()->bruchBeiId);
    }

    public function testRewrittenRowWithRecomputedHashStillBreaksTheNextRow(): void
    {
        $this->fuelle(3);
        $this->pdo()->exec("UPDATE audit_log SET action = 'x' WHERE id = 2");
        $row = $this->audit->neueste(3)[1];
        $this->pdo()->prepare('UPDATE audit_log SET hash = ? WHERE id = 2')->execute([$row->sollHash()]);
        self::assertSame(3, $this->audit->pruefen()->bruchBeiId);
    }

    public function testNewestFirst(): void
    {
        $this->fuelle(3);
        self::assertSame([3, 2, 1], array_map(static fn($e) => $e->id, $this->audit->neueste(10)));
        self::assertCount(2, $this->audit->neueste(2));
    }
}
