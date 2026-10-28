<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\FileKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobCipher;
use App\Service\Storage\BlobException;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Blob storage against a real database and a real directory, once per backend
 * (docs/spec/03-datenhaltung.md "Pflicht-Tests": round trip in both backends,
 * truncated and swapped chunks).
 *
 * What is being checked is not only "the file comes back": a file that was
 * cut short, reordered or opened with the wrong key must be an error and never
 * a shorter or garbled file, and nothing readable may be left on disk or in
 * the dump.
 */
final class BlobStorageTest extends DatabaseTestCase
{
    private const string CONTENT = 'Nachweis Max Mustermann, Geburtsurkunde, Sommerfest E-Jugend';

    private string $blobDir;

    private ServerCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/mv_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->crypto = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{BlobStorage}>
     */
    public static function backends(): array
    {
        return [
            'db' => [BlobStorage::Db],
            'fs' => [BlobStorage::Fs],
        ];
    }

    /**
     * @return array<string, array{BlobStorage, int}>
     */
    public static function backendsAndSizes(): array
    {
        $cases = [];
        foreach (self::backends() as $name => [$storage]) {
            // Around and well over the 64 KiB block size, so multi-block
            // streams and both chunk backends are actually exercised.
            foreach (['empty' => 0, 'small' => 64, 'one block' => 65536, 'several MiB' => 3 * 1024 * 1024 + 123] as $label => $size) {
                $cases[$name . ', ' . $label] = [$storage, $size];
            }
        }

        return $cases;
    }

    #[DataProvider('backendsAndSizes')]
    public function testRoundTrip(BlobStorage $storage, int $size): void
    {
        $content = self::binaryContent($size);
        $service = $this->service();

        $blob = $service->storeString($content, new BlobMeta('image/jpeg', 'scan.jpg'), $storage);

        self::assertSame($storage, $blob->storage);
        self::assertSame($size, $blob->size);
        self::assertTrue($blob->isComplete());
        self::assertSame($content, self::collect($service->openRead($blob)));
        // Once more from the stored row, not from the object store() built.
        self::assertSame($content, self::collect($service->openRead($this->reload($blob->id))));
    }

    #[DataProvider('backends')]
    public function testStreamRoundTripWithoutAPlaintextTempFile(BlobStorage $storage): void
    {
        $content = self::binaryContent(200_000);
        $service = $this->service();
        $source = fopen('php://memory', 'w+b');
        self::assertIsResource($source);
        fwrite($source, $content);
        rewind($source);

        $blob = $service->storeStream($source, new BlobMeta('application/pdf'), $storage);
        fclose($source);

        $before = self::allFiles($this->blobDir);
        $pieces = [];
        foreach ($service->openRead($blob) as $piece) {
            $pieces[] = $piece;
        }

        self::assertSame($content, implode('', $pieces));
        self::assertGreaterThan(1, count($pieces), 'the plaintext arrives in pieces, not in one go');
        // Reading creates nothing: no plaintext file next to the ciphertext.
        self::assertSame($before, self::allFiles($this->blobDir));
    }

    #[DataProvider('backends')]
    public function testMetadataIsStoredWithTheRow(BlobStorage $storage): void
    {
        $blob = $this->service()->storeString(self::CONTENT, new BlobMeta('application/pdf', 'Urkunde.pdf'), $storage);

        $reloaded = $this->reload($blob->id);
        self::assertSame('application/pdf', $reloaded->mimeType);
        self::assertSame('Urkunde.pdf', $reloaded->originalName);
    }

    #[DataProvider('backends')]
    public function testNothingReadableIsStored(BlobStorage $storage): void
    {
        $service = $this->service();

        $blob = $service->storeString(self::CONTENT, new BlobMeta('text/plain', 'urkunde.txt'), $storage);

        $stored = implode('', iterator_to_array($service->readCipher($blob), false));
        self::assertStringNotContainsString('Mustermann', $stored);
        self::assertStringNotContainsString('Geburtsurkunde', $stored);
        foreach (self::allFiles($this->blobDir) as $file) {
            self::assertStringNotContainsString('Mustermann', (string) file_get_contents($this->blobDir . '/' . $file));
        }

        $dump = $this->dumpOfAllTables();
        self::assertStringNotContainsString('Mustermann', $dump);
    }

    /**
     * The file key is in the database only as secretbox ciphertext under the
     * server key (docs/spec/02-sicherheit.md section 2): the raw key is
     * nowhere, and without the server key the stored value opens nothing.
     */
    #[DataProvider('backends')]
    public function testTheFileKeyIsStoredEncryptedUnderTheServerKey(BlobStorage $storage): void
    {
        $blob = $this->service()->storeString(self::CONTENT, new BlobMeta('text/plain'), $storage);

        $keyEnc = (string) $this->pdo()->query('SELECT key_enc FROM file_blob WHERE id = ' . $blob->id)->fetchColumn();
        $raw = $this->crypto->decrypt($keyEnc);
        self::assertSame(FileKey::BYTES, strlen($raw));
        self::assertStringNotContainsString($raw, $keyEnc);
        self::assertStringNotContainsString($raw, $this->dumpOfAllTables());

        $this->expectException(CryptoException::class);
        new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES))->decrypt($keyEnc);
    }

    #[DataProvider('backends')]
    public function testAnotherServerKeyCannotRead(BlobStorage $storage): void
    {
        $blob = $this->service()->storeString(self::CONTENT, new BlobMeta('text/plain'), $storage);
        $fremd = $this->service(new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES)));

        $this->expectException(CryptoException::class);
        self::collect($fremd->openRead($this->reload($blob->id)));
    }

    /**
     * A key_enc swapped between two rows opens (it is a valid ciphertext),
     * but it is the key of another file - the stream does not decrypt.
     */
    public function testTheKeyOfAnotherBlobCannotRead(): void
    {
        $service = $this->service();
        $first = $service->storeString(self::CONTENT, new BlobMeta('text/plain'), BlobStorage::Fs);
        $second = $service->storeString('etwas anderes', new BlobMeta('text/plain'), BlobStorage::Fs);

        $stmt = $this->pdo()->prepare('UPDATE file_blob SET key_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $second->keyEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $first->id, \PDO::PARAM_INT);
        $stmt->execute();

        $this->expectException(CryptoException::class);
        self::collect($service->openRead($this->reload($first->id)));
    }

    public function testFileNamesAreRandomIdsOnly(): void
    {
        $service = $this->service();

        $blob = $service->storeString(self::CONTENT, new BlobMeta('image/jpeg', 'Urkunde_Mustermann.jpg'), BlobStorage::Fs);

        self::assertNotNull($blob->fsName);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $blob->fsName);

        $files = self::allFiles($this->blobDir);
        self::assertCount(1, $files);
        self::assertStringNotContainsString('Mustermann', $files[0]);
        self::assertStringNotContainsString('.jpg', $files[0]);
        // Sharded by the first two characters, not a flat directory.
        self::assertSame(substr($blob->fsName, 0, 2) . '/' . $blob->fsName, $files[0]);
    }

    public function testDatabaseBackendKeepsChunksBelowTheRowLimit(): void
    {
        $service = $this->service();

        $blob = $service->storeString(self::binaryContent(700_000), new BlobMeta('application/pdf'), BlobStorage::Db);

        $sizes = $this->pdo()
            ->query('SELECT LENGTH(data) FROM file_blob_chunk WHERE blob_id = ' . $blob->id . ' ORDER BY seq')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertGreaterThan(1, count($sizes));
        foreach ($sizes as $size) {
            self::assertLessThanOrEqual(DbBlobBackend::CHUNK_BYTES, (int) $size);
        }
        self::assertEmpty(self::allFiles($this->blobDir), 'the db backend writes no files');
    }

    // ------------------------------------------------------- Manipulation

    #[DataProvider('backends')]
    public function testFlippedByteIsDetected(BlobStorage $storage): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::binaryContent(100_000), new BlobMeta('application/pdf'), $storage);
        self::assertTrue($service->verify($blob));

        $this->rewriteCipher($blob, static function (string $cipher): string {
            $cipher[50] = chr(ord($cipher[50]) ^ 0x01);

            return $cipher;
        });

        self::assertFalse($service->verify($this->reload($blob->id)));
        $this->expectException(CryptoException::class);
        self::collect($service->openRead($this->reload($blob->id)));
    }

    /** A file cut off at a block boundary is an error, not a shorter file. */
    #[DataProvider('backends')]
    public function testTruncatedBlobIsDetected(BlobStorage $storage): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::binaryContent(3 * BlobCipher::CHUNK_BYTES), new BlobMeta('application/pdf'), $storage);

        $this->rewriteCipher($blob, static fn(string $cipher): string => substr($cipher, 0, 2 * BlobCipher::BLOCK_BYTES));

        self::assertFalse($service->verify($this->reload($blob->id)));
        $this->expectException(CryptoException::class);
        self::collect($service->openRead($this->reload($blob->id)));
    }

    #[DataProvider('backends')]
    public function testSwappedBlocksAreDetected(BlobStorage $storage): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::binaryContent(3 * BlobCipher::CHUNK_BYTES), new BlobMeta('application/pdf'), $storage);

        $this->rewriteCipher($blob, static function (string $cipher): string {
            $first = substr($cipher, 0, BlobCipher::BLOCK_BYTES);
            $second = substr($cipher, BlobCipher::BLOCK_BYTES, BlobCipher::BLOCK_BYTES);

            return $second . $first . substr($cipher, 2 * BlobCipher::BLOCK_BYTES);
        });

        // The checksum covers the order too; reading does not look at it, so
        // the second line is the secretstream chain at work on its own.
        self::assertFalse($service->verify($this->reload($blob->id)));
        $this->expectException(CryptoException::class);
        self::collect($service->openRead($this->reload($blob->id)));
    }

    /** The db backend's own chunk rows swapped, which is not a block swap. */
    public function testSwappedDatabaseChunkRowsAreDetected(): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::binaryContent(700_000), new BlobMeta('application/pdf'), BlobStorage::Db);

        // Through a free seq, because the primary key forbids a direct swap.
        foreach ([[0, 99], [1, 0], [99, 1]] as [$from, $to]) {
            $this->pdo()->exec(sprintf(
                'UPDATE file_blob_chunk SET seq = %d WHERE blob_id = %d AND seq = %d',
                $to,
                $blob->id,
                $from,
            ));
        }

        $this->expectException(CryptoException::class);
        self::collect($service->openRead($this->reload($blob->id)));
    }

    public function testMissingFileIsAnErrorAndFailsVerify(): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::CONTENT, new BlobMeta('text/plain'), BlobStorage::Fs);

        unlink($this->blobDir . '/' . substr((string) $blob->fsName, 0, 2) . '/' . $blob->fsName);

        self::assertFalse($service->exists($blob));
        self::assertFalse($service->verify($blob));
        $this->expectException(BlobException::class);
        self::collect($service->openRead($blob));
    }

    // -------------------------------------------------------------- Rest

    #[DataProvider('backends')]
    public function testDeleteRemovesRowAndContent(BlobStorage $storage): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $storage);
        self::assertTrue($service->exists($blob));

        $service->delete($blob);

        self::assertNull(new BlobRepository($this->pdo())->find($blob->id));
        self::assertFalse($service->exists($blob));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob_chunk')->fetchColumn());
        self::assertEmpty(self::allFiles($this->blobDir));
    }

    /**
     * A write that breaks off leaves nothing behind that later looks like a
     * readable file.
     */
    #[DataProvider('backends')]
    public function testFailedWriteLeavesNoBlob(BlobStorage $storage): void
    {
        $service = $this->service();
        $source = (function (): \Generator {
            yield self::binaryContent(300_000);

            throw new \RuntimeException('Verbindung abgebrochen');
        })();

        try {
            $service->store($source, new BlobMeta('application/pdf'), $storage);
            self::fail('the broken write should have thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('Verbindung abgebrochen', $e->getMessage());
        }

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob_chunk')->fetchColumn());
        self::assertEmpty(self::allFiles($this->blobDir));
    }

    public function testDraftWithoutChecksumCannotBeRead(): void
    {
        $repository = new BlobRepository($this->pdo());
        $id = $repository->insertDraft(
            BlobStorage::Db,
            null,
            $this->crypto->encrypt(FileKey::generate()->raw()),
            str_repeat("\x01", 25),
            new BlobMeta('application/pdf'),
        );

        $blob = $repository->find($id);
        self::assertNotNull($blob);
        self::assertFalse($blob->isComplete());

        $this->expectException(BlobException::class);
        self::collect($this->service()->openRead($blob));
    }

    public function testConfiguredStorageComesFromTheSettingAndFallsBackToTheDefault(): void
    {
        $settings = new SettingRepository($this->pdo());
        self::assertSame(BlobStorage::Fs, BlobService::configuredStorage($settings));

        $settings->set(BlobService::SETTING_BACKEND, 'db');
        self::assertSame(BlobStorage::Db, BlobService::configuredStorage($settings));

        $settings->set(BlobService::SETTING_BACKEND, 'irgendwas');
        self::assertSame(BlobStorage::Fs, BlobService::configuredStorage($settings));
    }

    public function testTheDefaultStorageIsUsedWhenNoneIsGiven(): void
    {
        $repository = new BlobRepository($this->pdo());
        $service = new BlobService(
            $repository,
            new DbBlobBackend($repository),
            new FsBlobBackend($this->blobDir),
            $this->crypto,
            BlobStorage::Db,
        );

        $blob = $service->storeString(self::CONTENT, new BlobMeta('text/plain'));

        self::assertSame(BlobStorage::Db, $blob->storage);
        self::assertSame(self::CONTENT, self::collect($service->openRead($blob)));
    }

    private function reload(int $id): Blob
    {
        $blob = $this->service()->find($id);
        self::assertNotNull($blob);

        return $blob;
    }

    private function service(?ServerCrypto $crypto = null): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService(
            $repository,
            new DbBlobBackend($repository),
            new FsBlobBackend($this->blobDir),
            $crypto ?? $this->crypto,
        );
    }

    /**
     * Replaces the stored ciphertext of a blob with what $change makes of
     * it - on the shelf where it lies, behind the service's back.
     *
     * @param \Closure(string): string $change
     */
    private function rewriteCipher(Blob $blob, \Closure $change): void
    {
        $cipher = implode('', iterator_to_array($this->service()->readCipher($blob), false));
        $changed = $change($cipher);

        if ($blob->storage === BlobStorage::Fs) {
            file_put_contents($this->blobDir . '/' . substr((string) $blob->fsName, 0, 2) . '/' . $blob->fsName, $changed);

            return;
        }

        $this->pdo()->exec('DELETE FROM file_blob_chunk WHERE blob_id = ' . $blob->id);
        $repository = new BlobRepository($this->pdo());
        foreach (str_split($changed, DbBlobBackend::CHUNK_BYTES) as $seq => $piece) {
            $repository->insertChunk($blob->id, $seq, $piece);
        }
    }

    private function dumpOfAllTables(): string
    {
        $dump = '';
        foreach (['file_blob', 'file_blob_chunk'] as $table) {
            foreach ($this->pdo()->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
                $dump .= implode('|', array_map(strval(...), $row));
            }
        }

        return $dump;
    }

    /**
     * @param iterable<string> $chunks
     */
    private static function collect(iterable $chunks): string
    {
        $result = '';
        foreach ($chunks as $chunk) {
            $result .= $chunk;
        }

        return $result;
    }

    private static function binaryContent(int $size): string
    {
        if ($size === 0) {
            return '';
        }

        // Binary, with the bytes a naive implementation trips over: NUL,
        // 0xFF, newlines.
        $pattern = "\x00\xff\x0a\x0dScan\x1a";

        return substr(str_repeat($pattern, (int) ($size / strlen($pattern)) + 1), 0, $size);
    }

    /**
     * @return list<string>
     */
    private static function allFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
