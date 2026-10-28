<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Service\Backup\BackupCipher;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\Backup\FalscherSchluessel;
use App\Service\Backup\RestoreService;
use App\Service\Backup\Wiederherstellung;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;

/**
 * Pflicht-Test of docs/spec/04-installation-und-update.md section 6 and
 * 02 section 2: make a backup, wipe database, blob directory and logo,
 * restore - everything must be back. The backup is encrypted, readable only
 * with the key, and a wrong key is refused before anything is restored.
 *
 * Both chains run with tiny budgets, so every phase takes several steps -
 * the way they run on the host, one short request each.
 */
final class BackupRestoreRoundtripTest extends DatabaseTestCase
{
    private const string KLARTEXT = 'Nachweis Erika Mustermann, Schülerausweis 2026';

    private string $root;

    private string $backupDir;

    private string $blobDir;

    private string $logoDir;

    private string $schluessel;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->root = sys_get_temp_dir() . '/mv_backup_' . uniqid('', true);
        $this->backupDir = $this->root . '/backups';
        $this->blobDir = $this->root . '/blobs';
        $this->logoDir = $this->root . '/logo';
        foreach ([$this->backupDir, $this->blobDir, $this->logoDir] as $dir) {
            mkdir($dir, 0775, true);
        }
        $this->schluessel = random_bytes(ServerCrypto::KEY_BYTES);
    }

    protected function tearDown(): void
    {
        self::entfernen($this->root);
        parent::tearDown();
    }

    public function testBackupRestoreRoundtrip(): void
    {
        // Values that break a naive dump: umlauts, both quote kinds, a
        // semicolon, backslashes, a comment marker, a line break, binary.
        $stmt = $this->pdo()->prepare('INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)');
        foreach ([
            'umlaute' => json_encode('Grün-Weiß „Süd“ Straße', JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'quotes' => json_encode("O'Brien \"zitiert\"; DROP TABLE setting; --", JSON_THROW_ON_ERROR),
            'backslash' => json_encode('C:\\pfad\\neu \\\' ende', JSON_THROW_ON_ERROR),
            'zeilen' => json_encode("Zeile 1\nZeile 2\r\n# kein Kommentar", JSON_THROW_ON_ERROR),
        ] as $name => $value) {
            $stmt->execute([$name, $value, '2026-01-02 03:04:05']);
        }

        $blobs = $this->blobService();
        $inhaltFs = self::KLARTEXT . str_repeat(' fs', 50_000);
        $inhaltDb = self::KLARTEXT . str_repeat(' db', 120_000);
        $blobFs = $blobs->storeString($inhaltFs, new BlobMeta('application/pdf', 'nachweis.pdf'), BlobStorage::Fs);
        $blobDb = $blobs->storeString($inhaltDb, new BlobMeta('image/jpeg', 'scan.jpg'), BlobStorage::Db);
        $logo = "\x89PNG\r\n\x1a\n" . random_bytes(2000);
        file_put_contents($this->logoDir . '/logo.png', $logo);

        $vorher = $this->tabellen();
        $blobDateienVorher = $this->blobDateien();
        self::assertCount(1, $blobDateienVorher);

        $name = $this->erstellung()->komplett();

        $pfad = $this->backupDir . '/' . $name;
        self::assertMatchesRegularExpression(BackupService::NAME, $name);
        self::assertTrue(BackupCipher::istBackup($pfad));
        $datei = (string) file_get_contents($pfad);
        // The dump carries the data in the clear; the file must not.
        foreach (['Grün-Weiß', "O'Brien", 'CREATE TABLE', 'dump.sql', 'manifest.json', "PK\x03\x04", 'nachweis.pdf'] as $verraeterisch) {
            self::assertStringNotContainsString($verraeterisch, $datei, $verraeterisch);
        }
        self::assertSame([], glob($this->backupDir . '/erstellung_*.part'), 'no plaintext work file may survive');

        // Gone: database, blob files, logo.
        self::dropAllTables($this->pdo());
        self::entfernen($this->blobDir);
        self::entfernen($this->logoDir);

        $stand = $this->wiederherstellen($pfad, $this->schluessel);

        self::assertSame(Wiederherstellung::PHASE_FERTIG, $stand['phase']);
        self::assertSame(0, $stand['abgelehnt']);
        self::assertSame(0, $stand['migrationen'], 'the backup was made on the current schema');
        self::assertSame('9.9.9-test', $stand['manifest']['app_version']);
        self::assertSame(1, $stand['manifest']['blob_dateien'], 'one fs blob in the ZIP (the db blob rides in the dump)');
        self::assertSame(1, $stand['manifest']['logo_dateien']);
        self::assertSame($vorher, $this->tabellen());
        self::assertSame($blobDateienVorher, $this->blobDateien());
        self::assertSame($logo, file_get_contents($this->logoDir . '/logo.png'));
        self::assertSame(100, Wiederherstellung::fortschritt($stand));
        self::assertSame([], glob($this->root . '/arbeit/wiederherstellung_*'), 'no plaintext work file may survive');

        // And the files read again with the same server key.
        $blobs = $this->blobService();
        self::assertSame($inhaltFs, implode('', iterator_to_array($blobs->openRead($blobs->find($blobFs->id) ?? self::fail()), false)));
        self::assertSame($inhaltDb, implode('', iterator_to_array($blobs->openRead($blobs->find($blobDb->id) ?? self::fail()), false)));
    }

    public function testRestoreAppliesTheMigrationsTheBackupDoesNotHave(): void
    {
        $name = $this->erstellung()->komplett();
        self::dropAllTables($this->pdo());

        // The release restoring the backup is newer: one migration more.
        $neuer = $this->root . '/migrations';
        mkdir($neuer);
        foreach (glob($this->migrationsDir() . '/*.sql') ?: [] as $datei) {
            copy($datei, $neuer . '/' . basename($datei));
        }
        file_put_contents($neuer . '/999_backup_test.sql', 'CREATE TABLE backup_test (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;');

        $stand = $this->wiederherstellen($this->backupDir . '/' . $name, $this->schluessel, $neuer);

        self::assertSame(1, $stand['migrationen']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM schema_version WHERE version = 999')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM backup_test')->fetchColumn());
    }

    public function testAWrongKeyIsRefusedAndNothingIsRestored(): void
    {
        $this->pdo()->exec("INSERT INTO setting (name, value, updated_at) VALUES ('bleibt', '1', '2026-01-01 00:00:00')");
        $name = $this->erstellung()->komplett();
        $this->pdo()->exec("DELETE FROM setting WHERE name = 'bleibt'");

        try {
            $this->restoreKette()->starten($this->backupDir . '/' . $name, random_bytes(ServerCrypto::KEY_BYTES));
            self::fail('A wrong key must be refused.');
        } catch (FalscherSchluessel $e) {
            self::assertSame('Der Sicherungsschlüssel passt nicht zu diesem Backup.', $e->getMessage());
        }

        self::assertNull($this->restoreKette()->stand());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM setting WHERE name = 'bleibt'")->fetchColumn());
    }

    public function testAStepWithAnotherKeyThanTheStartFails(): void
    {
        // Large enough for several decryption steps.
        $this->blobService()->storeString(random_bytes(400_000), new BlobMeta('image/jpeg', 'gross.jpg'), BlobStorage::Fs);
        $name = $this->erstellung()->komplett();
        $kette = $this->restoreKette();
        $kette->starten($this->backupDir . '/' . $name, $this->schluessel);
        $kette->schritt($this->pdo(), $this->schluessel);

        $this->expectException(CryptoException::class);
        $kette->schritt($this->pdo(), random_bytes(ServerCrypto::KEY_BYTES));
    }

    public function testWithoutTheKeyTheBackupIsNoZip(): void
    {
        $name = $this->erstellung()->komplett();

        $zip = new \ZipArchive();
        self::assertNotTrue($zip->open($this->backupDir . '/' . $name, \ZipArchive::RDONLY));
    }

    public function testAStatementThatFailsIsNotTakenForOneThatRanAlready(): void
    {
        $name = $this->erstellung()->komplett();
        $kette = $this->restoreKette();
        $stand = $kette->starten($this->backupDir . '/' . $name, $this->schluessel);
        while ($stand['phase'] !== Wiederherstellung::PHASE_DUMP) {
            $stand = $kette->schritt($this->pdo(), $this->schluessel);
        }
        // A statement the database refuses, right at the start.
        $dump = $this->root . '/arbeit/wiederherstellung_' . $stand['id'] . '.sql';
        file_put_contents($dump, "INSERT INTO gibt_es_nicht VALUES (1);\n" . file_get_contents($dump));

        for ($versuch = 0; $versuch < 2; $versuch++) {
            try {
                $kette->schritt($this->pdo(), $this->schluessel);
                self::fail('The failing statement must stop the step, also when retried.');
            } catch (\PDOException) {
            }
            self::assertFalse($kette->stand()['dump_laeuft'] ?? null);
            self::assertSame(0, $kette->stand()['dump_offset'] ?? null);
        }
    }

    public function testTheCronDiscardsAnAbandonedRestoreWithItsPlaintext(): void
    {
        $name = $this->erstellung()->komplett();
        $kette = $this->restoreKette();
        $kette->starten($this->backupDir . '/' . $name, $this->schluessel);
        $kette->schritt($this->pdo(), $this->schluessel);
        self::assertNotSame([], glob($this->root . '/arbeit/wiederherstellung_*'));

        self::assertFalse($kette->verwaistAufraeumen(new \DateTimeImmutable()), 'a restore just advanced stays');
        self::assertTrue($kette->verwaistAufraeumen(new \DateTimeImmutable('+2 hours')));
        self::assertSame([], glob($this->root . '/arbeit/wiederherstellung_*'));
        self::assertNull($kette->stand());
    }

    public function testTheKeyIsTakenFromTheInputOrAnUploadedConfig(): void
    {
        $base64 = base64_encode($this->schluessel);

        self::assertSame($this->schluessel, RestoreService::schluesselAusEingabe($base64));
        // As typed from a printout: in groups, over two lines.
        self::assertSame($this->schluessel, RestoreService::schluesselAusEingabe(implode(' ', str_split(substr($base64, 0, 20), 4)) . "\n" . substr($base64, 20)));
        self::assertNull(RestoreService::schluesselAusEingabe('zu-kurz'));
        self::assertNull(RestoreService::schluesselAusEingabe(base64_encode(random_bytes(16))));

        $config = "<?php\nreturn [\n    'db' => ['host' => 'localhost'],\n    'server_key' => '" . $base64 . "',\n];\n";
        self::assertSame($this->schluessel, RestoreService::schluesselAusConfig($config));
        self::assertNull(RestoreService::schluesselAusConfig("<?php return ['db' => []];"));
    }

    public function testAStepThatDiedHalfwayIsSimplyRunAgain(): void
    {
        $this->blobService()->storeString(self::KLARTEXT . str_repeat('x', 300_000), new BlobMeta('image/jpeg', 'a.jpg'), BlobStorage::Fs);
        for ($i = 0; $i < 400; $i++) {
            $this->pdo()->prepare('INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)')
                ->execute(['s' . $i, json_encode(str_repeat('wert ' . $i . ' ', 40), JSON_THROW_ON_ERROR), '2026-01-01 00:00:00']);
        }
        $vorher = $this->tabellen();
        $erstellung = $this->erstellung();

        // Every step is run twice: the first run's status is thrown away, as
        // if the request had died after writing but before saving.
        $statusDatei = $this->backupDir . '/erstellung.json';
        $stand = $erstellung->starten();
        while ($stand['phase'] !== BackupErstellung::PHASE_FERTIG) {
            $gesichert = (string) file_get_contents($statusDatei);
            $erstellung->schritt();
            if (json_decode((string) file_get_contents($statusDatei), true)['phase'] !== BackupErstellung::PHASE_FERTIG) {
                file_put_contents($statusDatei, $gesichert);
            }
            $stand = $erstellung->schritt();
        }

        self::dropAllTables($this->pdo());
        self::entfernen($this->blobDir);

        $kette = $this->restoreKette();
        $statusDatei = $this->root . '/arbeit/wiederherstellung.json';
        $stand = $kette->starten($this->backupDir . '/' . $stand['name'], $this->schluessel);
        while ($stand['phase'] !== Wiederherstellung::PHASE_FERTIG) {
            $gesichert = json_decode((string) file_get_contents($statusDatei), true);
            $kette->schritt($this->pdo(), $this->schluessel);
            if ($gesichert['phase'] === Wiederherstellung::PHASE_DUMP) {
                // The dump confirms every statement as it goes; a hard break
                // leaves "step running" set and repeats at most the last one.
                $nachher = json_decode((string) file_get_contents($statusDatei), true);
                file_put_contents($statusDatei, json_encode([...$nachher, 'phase' => Wiederherstellung::PHASE_DUMP, 'dump_offset' => $this->vorletzteGrenze($nachher), 'dump_laeuft' => true], JSON_THROW_ON_ERROR));
            } elseif ($gesichert['phase'] !== Wiederherstellung::PHASE_MIGRATIONEN) {
                file_put_contents($statusDatei, json_encode($gesichert, JSON_THROW_ON_ERROR));
            }
            $stand = $kette->schritt($this->pdo(), $this->schluessel);
        }

        self::assertSame($vorher, $this->tabellen());
    }

    /**
     * The boundary of the statement before the last one confirmed - the
     * place a step resumes when its last statement ran but was not recorded.
     *
     * @param array<string, mixed> $stand
     */
    private function vorletzteGrenze(array $stand): int
    {
        $dump = $this->root . '/arbeit/wiederherstellung_' . $stand['id'] . '.sql';
        $stream = new \App\Service\Migration\SqlStatementStream($dump);
        $vorher = 0;
        $grenze = 0;
        while ($stream->boundary() < (int) $stand['dump_offset'] && $stream->next() !== null) {
            $vorher = $grenze;
            $grenze = $stream->boundary();
        }

        return $vorher;
    }

    /**
     * @return array<string, mixed>
     */
    private function wiederherstellen(string $pfad, string $schluessel, ?string $migrationen = null): array
    {
        $kette = $this->restoreKette($migrationen);
        $stand = $kette->starten($pfad, $schluessel);
        $schritte = 0;
        while ($stand['phase'] !== Wiederherstellung::PHASE_FERTIG) {
            $stand = $kette->schritt($this->pdo(), $schluessel);
            self::assertLessThan(10_000, ++$schritte);
            // Every step answers JSON-safe data with a progress figure.
            self::assertIsInt(Wiederherstellung::fortschritt($stand));
        }

        return $stand;
    }

    private function erstellung(): BackupErstellung
    {
        static $sekunde = 0;
        $zeit = new \DateTimeImmutable('2026-03-01 10:00:00')->modify('+' . $sekunde++ . ' seconds');
        $backups = new BackupService(
            $this->pdo(),
            $this->backupDir,
            '9.9.9-test',
            $this->blobDir,
            $this->logoDir,
            static fn(): \DateTimeImmutable => $zeit,
        );

        // Tiny budgets: several steps per phase.
        return new BackupErstellung($backups, $this->pdo(), new ServerCrypto($this->schluessel), 4096, 60.0, 100_000);
    }

    private function restoreKette(?string $migrationen = null): Wiederherstellung
    {
        return new Wiederherstellung(
            $this->root . '/arbeit',
            $this->blobDir,
            $this->logoDir,
            $migrationen ?? $this->migrationsDir(),
            entschluesselnBytes: 100_000,
        );
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir), new ServerCrypto($this->schluessel));
    }

    /**
     * Every table with every row, ordered - binary columns as hex.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function tabellen(): array
    {
        $ergebnis = [];
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $tabelle) {
            $zeilen = $this->pdo()->query(sprintf('SELECT * FROM `%s` ORDER BY 1, 2', (string) $tabelle))->fetchAll(\PDO::FETCH_ASSOC);
            $ergebnis[(string) $tabelle] = array_map(
                static fn(array $zeile): array => array_map(
                    static fn(mixed $wert): mixed => is_string($wert) && !mb_check_encoding($wert, 'UTF-8') ? bin2hex($wert) : $wert,
                    $zeile,
                ),
                $zeilen,
            );
        }
        ksort($ergebnis);

        return $ergebnis;
    }

    /**
     * @return array<string, string> relative path => sha256
     */
    private function blobDateien(): array
    {
        $ergebnis = [];
        foreach (glob($this->blobDir . '/*/*') ?: [] as $pfad) {
            $ergebnis[basename(dirname($pfad)) . '/' . basename($pfad)] = (string) hash_file('sha256', $pfad);
        }
        ksort($ergebnis);

        return $ergebnis;
    }

    private static function entfernen(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $datei) {
            if ($datei instanceof \SplFileInfo) {
                $datei->isDir() ? rmdir($datei->getPathname()) : unlink($datei->getPathname());
            }
        }
        rmdir($dir);
    }
}
