<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Backup\BackupCipher;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupLaeuftBereits;
use App\Service\Backup\BackupService;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The backup step chain around the round trip (04 section 6): rotation to
 * ten, one chain at a time, cancelling, the cron's clean-up of an abandoned
 * chain (its work file holds the dump in the clear), and what goes into the
 * ZIP.
 */
final class BackupErstellungTest extends DatabaseTestCase
{
    private string $root;

    private string $schluessel;

    private \DateTimeImmutable $jetzt;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->root = sys_get_temp_dir() . '/mv_erstellung_' . uniqid('', true);
        foreach (['backups', 'blobs/ab', 'logo'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
        $this->schluessel = random_bytes(ServerCrypto::KEY_BYTES);
        $this->jetzt = new \DateTimeImmutable('2026-05-01 12:00:00');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $datei) {
            if ($datei instanceof \SplFileInfo) {
                $datei->isDir() ? rmdir($datei->getPathname()) : unlink($datei->getPathname());
            }
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testOnlyTheNewestTenAreKept(): void
    {
        $namen = [];
        for ($i = 0; $i < 12; $i++) {
            $namen[] = $this->erstellung()->komplett();
            $this->jetzt = $this->jetzt->modify('+1 day');
        }

        $liste = array_column($this->backups()->list(), 'name');
        self::assertCount(BackupService::KEEP, $liste);
        self::assertSame(array_reverse(array_slice($namen, 2)), $liste);
        self::assertSame('backup_20260512_120000.zip.enc', $liste[0]);
        self::assertEquals(new \DateTimeImmutable('2026-05-12 12:00:00'), $this->backups()->list()[0]['erstellt_am']);
    }

    public function testTwoBackupsInTheSameSecondGetTwoNames(): void
    {
        $erster = $this->erstellung()->komplett();
        $zweiter = $this->erstellung()->komplett();

        self::assertSame('backup_20260501_120000.zip.enc', $erster);
        self::assertSame('backup_20260501_120000_2.zip.enc', $zweiter);
    }

    public function testOneChainAtATime(): void
    {
        $this->erstellung()->starten();

        $this->expectException(BackupLaeuftBereits::class);
        $this->erstellung()->starten();
    }

    public function testNoBackupWhileTheServerKeyIsBeingChanged(): void
    {
        $erstellung = new BackupErstellung(
            $this->backups($this->jetzt),
            $this->pdo(),
            new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES), $this->schluessel),
            2048,
        );

        $this->expectException(BackupLaeuftBereits::class);
        $this->expectExceptionMessage('Server-Schlüssel');

        $erstellung->starten();
    }

    public function testCancellingRemovesWorkFilesAndState(): void
    {
        $erstellung = $this->erstellung();
        $erstellung->starten();
        $erstellung->schritt();
        self::assertNotSame([], glob($this->root . '/backups/erstellung_*.part'));

        $erstellung->abbrechen();

        self::assertSame([], glob($this->root . '/backups/erstellung_*.part'));
        self::assertNull($erstellung->stand());
        self::assertFalse($erstellung->laeuft());
        self::assertSame([], $this->backups()->list());
    }

    public function testTheCronDiscardsAnAbandonedChainOnly(): void
    {
        $erstellung = $this->erstellung();
        $erstellung->starten();
        $erstellung->schritt();

        self::assertFalse($erstellung->verwaistAufraeumen(), 'a running chain stays');
        self::assertNotSame([], glob($this->root . '/backups/erstellung_*.part'));

        $this->jetzt = $this->jetzt->modify('+' . BackupErstellung::VERWAIST_NACH_SEKUNDEN . ' seconds');
        $spaeter = $this->erstellung();
        self::assertFalse($spaeter->laeuft());
        self::assertTrue($spaeter->verwaistAufraeumen());
        self::assertSame([], glob($this->root . '/backups/erstellung_*.part'));
        self::assertNull($spaeter->stand());

        // A finished chain leaves nothing to clean up.
        $spaeter->komplett();
        self::assertFalse($spaeter->verwaistAufraeumen());
        self::assertCount(1, $this->backups()->list());
    }

    public function testTheZipHoldsDumpFilesLogoAndManifest(): void
    {
        $blob = str_repeat('ab', 16);
        file_put_contents($this->root . '/blobs/ab/' . $blob, random_bytes(500));
        file_put_contents($this->root . '/blobs/ab/' . $blob . '.part', 'abgebrochen');
        file_put_contents($this->root . '/blobs/ab/fremd.txt', 'nicht von uns');
        file_put_contents($this->root . '/logo/logo.png', 'PNG');
        file_put_contents($this->root . '/logo/.versteckt', 'nein');

        $namen = $this->eintraege($this->erstellung()->komplett());
        self::assertSame(['dump.sql', 'blobs/ab/' . $blob, 'logo/logo.png', 'manifest.json'], $namen);

        $ohne = $this->eintraege($this->erstellung(mitZeit: '+1 second')->komplett(false));
        self::assertSame(['dump.sql', 'logo/logo.png', 'manifest.json'], $ohne, 'the update backup leaves the blob files out');
    }

    public function testTheManifestHoldsVersionsAndNumbersOnly(): void
    {
        $zip = $this->entschluesselt($this->erstellung()->komplett());
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        self::assertSame(['app_version', 'schema_version', 'erstellt_am', 'blob_dateien', 'blob_bytes', 'logo_dateien'], array_keys($manifest));
        self::assertSame('9.9.9-test', $manifest['app_version']);
        self::assertSame('2026-05-01 12:00:00', $manifest['erstellt_am']);
        self::assertGreaterThan(0, $manifest['schema_version']);
    }

    public function testAFileThatVanishedStopsTheChain(): void
    {
        $blob = str_repeat('ab', 16);
        file_put_contents($this->root . '/blobs/ab/' . $blob, 'x');
        $erstellung = $this->erstellung();
        $stand = $erstellung->starten();
        while ($stand['phase'] === BackupErstellung::PHASE_DUMP) {
            $stand = $erstellung->schritt();
        }
        unlink($this->root . '/blobs/ab/' . $blob);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verschwunden');
        $erstellung->schritt();
    }

    public function testRotationRemovesAnEmptyNameLeftByADeadRequest(): void
    {
        [, $leer] = $this->backups()->reserveName($this->jetzt->modify('-3 hours'));
        touch($leer, $this->jetzt->modify('-3 hours')->getTimestamp());
        [, $frisch] = $this->backups()->reserveName($this->jetzt);
        touch($frisch, $this->jetzt->getTimestamp());

        self::assertSame(1, $this->backups()->rotate());
        self::assertFileDoesNotExist($leer);
        self::assertFileExists($frisch, 'a name reserved a moment ago belongs to a running hand-over');
        self::assertSame([], $this->backups()->list());
    }

    public function testPathRefusesAnythingButABackupName(): void
    {
        $name = $this->erstellung()->komplett();
        $backups = $this->backups();

        self::assertNotNull($backups->path($name));
        foreach (['../config.php', 'erstellung.json', 'backup_20260501_120000.zip', $name . '/..', 'backup_1_2.zip.enc'] as $falsch) {
            self::assertNull($backups->path($falsch), $falsch);
        }
    }

    /**
     * @return list<string>
     */
    private function eintraege(string $name): array
    {
        $zip = $this->entschluesselt($name);
        $namen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $namen[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $namen;
    }

    private function entschluesselt(string $name): \ZipArchive
    {
        $ziel = $this->root . '/' . $name . '.zip';
        BackupCipher::dateiEntschluesseln($this->root . '/backups/' . $name, $ziel, new ServerCrypto($this->schluessel));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($ziel, \ZipArchive::RDONLY));

        return $zip;
    }

    private function backups(?\DateTimeImmutable $zeit = null): BackupService
    {
        $zeit ??= $this->jetzt;

        return new BackupService(
            $this->pdo(),
            $this->root . '/backups',
            '9.9.9-test',
            $this->root . '/blobs',
            $this->root . '/logo',
            static fn(): \DateTimeImmutable => $zeit,
        );
    }

    private function erstellung(string $mitZeit = '+0 seconds'): BackupErstellung
    {
        return new BackupErstellung($this->backups($this->jetzt->modify($mitZeit)), $this->pdo(), new ServerCrypto($this->schluessel), 2048);
    }
}
