<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamResponse;
use App\Service\Backup\BackupCipher;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\Crypto\ServerCrypto;
use App\Tests\Support\AppTestCase;

/**
 * The settings section Backups through the whole application (04 section
 * 6): the chain as the page's script drives it, the list, the download. The
 * backup directory is a temporary one - the services are registered before
 * the routes ask for them.
 */
final class BackupSeiteTest extends AppTestCase
{
    private string $root;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mv_backupseite_' . uniqid('', true);
        mkdir($this->root . '/backups', 0775, true);

        $backups = new BackupService($this->dienste->pdo(), $this->root . '/backups', '0.0.0-test', $this->root . '/blobs', $this->root . '/logo');
        $this->dienste->dienst(BackupService::class, static fn(): BackupService => $backups);
        $this->dienste->dienst(
            BackupErstellung::class,
            fn(): BackupErstellung => new BackupErstellung($backups, $this->dienste->pdo(), ServerCrypto::aus($this->dienste), 4096),
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/backups/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->root . '/backups');
        rmdir($this->root);
        parent::tearDown();
    }

    public function testTheSettingsPageShowsTheSectionWithItsScript(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/admin')->body;

        self::assertStringContainsString('<h2 id="titel-backups" tabindex="-1">Backups</h2>', $seite);
        self::assertStringContainsString('id="backup-erstellen"', $seite);
        self::assertStringContainsString('<script src="/js/backups.js?v=0.0.0-test" defer></script>', $seite);
        self::assertStringContainsString('Noch kein Backup vorhanden', $seite);
        self::assertStringNotContainsString('Backup abbrechen', $seite);
    }

    public function testTheChainMakesABackupThatTheListOffersForDownload(): void
    {
        $this->als(Rolle::Admin);

        $stand = $this->json($this->post('/admin/backups'));
        self::assertSame('dump', $stand['phase']);
        $schritte = 0;
        while ($stand['fertig'] !== true) {
            $stand = $this->json($this->post('/admin/backups/schritt'));
            self::assertLessThan(1000, ++$schritte);
            // Nothing about paths or the club in the answer.
            self::assertSame(['phase', 'fertig', 'dump_zeilen', 'dateien', 'dateien_gesamt', 'verschluesselt', 'zip_bytes'], array_keys($stand));
        }
        self::assertGreaterThan(1, $schritte);

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Das Backup ist erstellt.', $seite);
        $name = basename((string) glob($this->root . '/backups/backup_*.zip.enc')[0]);
        self::assertStringContainsString('href="/admin/backups/' . $name . '"', $seite);

        $antwort = $this->kernel->handle(new Request(HttpMethod::Get, '/admin/backups/' . $name));
        self::assertInstanceOf(StreamResponse::class, $antwort);
        self::assertSame('attachment; filename="' . $name . '"', $antwort->headers['Content-Disposition']);
        self::assertSame('no-store', $antwort->headers['Cache-Control']);
        $inhalt = implode('', iterator_to_array($antwort->chunks, false));
        self::assertSame(file_get_contents($this->root . '/backups/' . $name), $inhalt);
        self::assertStringStartsWith(BackupCipher::MAGIC, $inhalt);

        // 02 section 8: the backup is in the audit trail, once.
        $audit = $this->dienste->pdo()->query("SELECT entity, entity_id FROM audit_log WHERE action = 'backup.erstellt'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['entity' => 'backup', 'entity_id' => $name]], $audit);
        $download = $this->dienste->pdo()->query("SELECT entity_id FROM audit_log WHERE action = 'backup.heruntergeladen'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([$name], $download, 'the download is recorded too');
    }

    public function testASecondStartWhileOneRunsIsRefused(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/backups');

        $antwort = $this->post('/admin/backups');

        self::assertSame(409, $antwort->status);
        self::assertSame('Es wird bereits ein Backup erstellt.', $this->json($antwort)['fehler']);
        self::assertStringContainsString('Backup fortsetzen', $this->get('/admin')->body);
    }

    public function testCancellingEndsTheChain(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/backups');
        $this->post('/admin/backups/schritt');
        self::assertStringContainsString('Backup abbrechen', $this->get('/admin')->body);

        $antwort = $this->post('/admin/backups/abbrechen');

        self::assertWeiterleitungNach('/admin#backups', $antwort);
        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Die Backup-Erstellung ist abgebrochen.', $seite);
        self::assertStringNotContainsString('Backup abbrechen', $seite);
        self::assertSame([], glob($this->root . '/backups/erstellung_*.part'));
    }

    public function testAStepWithoutARunningChainAnswersAnError(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/backups/schritt');

        self::assertSame(500, $antwort->status);
        self::assertSame('Es läuft keine Backup-Erstellung.', $this->json($antwort)['fehler']);
    }

    public function testAnythingButABackupNameIsNotFound(): void
    {
        $this->als(Rolle::Admin);
        file_put_contents($this->root . '/backups/erstellung.json', '{}');

        foreach (['erstellung.json', 'backup_20260101_120000.zip.enc', '..%2Fconfig.php'] as $name) {
            self::assertSame(404, $this->get('/admin/backups/' . $name)->status, $name);
        }
    }

    public function testTheBoardCannotReachTheBackups(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/admin/backups')->status);
        self::assertSame(403, $this->get('/admin/backups/backup_20260101_120000.zip.enc')->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $antwort): array
    {
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }
}
