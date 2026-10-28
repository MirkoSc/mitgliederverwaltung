<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dienste;
use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;

/**
 * The `file_blob` and `file_blob_chunk` tables (docs/spec/03-datenhaltung.md
 * section 2). SQL lives in repositories only, prepared statements only.
 *
 * A blob is written in two steps, because the stored file needs the row
 * first: insertDraft() reserves the row, complete() turns it into a readable
 * blob once the stream is through. A row without `cipher_sha256` is a write
 * that broke off.
 */
final readonly class BlobRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public static function aus(Dienste $d): self
    {
        return $d->dienst(self::class, static fn(Dienste $d): self => new self($d->pdo()));
    }

    /**
     * Reserves a blob row. The content follows; the checksum makes it
     * readable.
     */
    public function insertDraft(
        BlobStorage $storage,
        ?string $fsName,
        string $keyEnc,
        string $header,
        BlobMeta $meta,
        ?\DateTimeImmutable $now = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO file_blob (storage, fs_name, size, key_enc, header, mime, original_name, created_at)
             VALUES (?, ?, 0, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $storage->value);
        $stmt->bindValue(2, $fsName);
        $stmt->bindValue(3, $keyEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $header, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $meta->mimeType);
        $stmt->bindValue(6, $meta->originalName);
        $stmt->bindValue(7, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** Plaintext length and ciphertext checksum - the blob is readable from here on. */
    public function complete(int $id, int $size, string $cipherSha256): void
    {
        $stmt = $this->pdo->prepare('UPDATE file_blob SET size = ?, cipher_sha256 = ? WHERE id = ?');
        $stmt->bindValue(1, $size, \PDO::PARAM_INT);
        $stmt->bindValue(2, $cipherSha256, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function find(int $id): ?Blob
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_blob WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : Blob::fromRow($row);
    }

    /** Chunk rows go with the row (ON DELETE CASCADE). */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM file_blob WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function insertChunk(int $blobId, int $seq, string $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO file_blob_chunk (blob_id, seq, data) VALUES (?, ?, ?)');
        $stmt->bindValue(1, $blobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $seq, \PDO::PARAM_INT);
        $stmt->bindValue(3, $data, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * One chunk at a time, on purpose: PDO buffers a whole result set, so
     * iterating "SELECT data ... ORDER BY seq" would pull the entire file
     * into memory - exactly what streaming is meant to avoid.
     */
    public function findChunk(int $blobId, int $seq): ?string
    {
        $stmt = $this->pdo->prepare('SELECT data FROM file_blob_chunk WHERE blob_id = ? AND seq = ?');
        $stmt->execute([$blobId, $seq]);
        $data = $stmt->fetchColumn();

        return $data === false ? null : (string) $data;
    }

    public function countChunks(int $blobId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM file_blob_chunk WHERE blob_id = ?');
        $stmt->execute([$blobId]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteChunks(int $blobId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM file_blob_chunk WHERE blob_id = ?');
        $stmt->execute([$blobId]);
    }
}
