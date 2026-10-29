<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Dienste;
use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\FileKey;
use App\Service\Crypto\ServerCrypto;

/**
 * Encrypted file storage (docs/spec/02-sicherheit.md section 2,
 * docs/spec/03-datenhaltung.md section 3): proof scans, archived direct
 * debit files.
 *
 * Every file gets a random key of its own; that key is stored encrypted
 * under the server key, the content as a secretstream. Both directions work
 * in pieces - nothing here builds a plaintext temporary file, and nothing
 * holds a whole file in memory, because a 20 MB scan has to fit through a
 * shared host's memory limit and its 30 seconds.
 *
 * Taken over from Vereinsbelege without the vault: there the data key was
 * sealed to a public key so that the public submission could write without a
 * secret; here the server key does that job, and anything that runs on the
 * server (a request, the cron) can read.
 */
final readonly class BlobService
{
    /** Admin setting for the storage backend. */
    public const string SETTING_BACKEND = 'speicher_backend';

    public function __construct(
        private BlobRepository $repository,
        private DbBlobBackend $dbBackend,
        private FsBlobBackend $fsBackend,
        private ServerCrypto $crypto,
        private BlobStorage $defaultStorage = BlobStorage::Fs,
    ) {
    }

    public static function aus(Dienste $d): self
    {
        return $d->dienst(self::class, static function (Dienste $d): self {
            $repository = BlobRepository::aus($d);

            return new self(
                $repository,
                new DbBlobBackend($repository),
                new FsBlobBackend($d->paths->blobDir()),
                ServerCrypto::aus($d),
                self::configuredStorage($d->einstellungen()),
            );
        });
    }

    /** The backend an installation writes new blobs to (E-08). */
    public static function configuredStorage(SettingRepository $settings): BlobStorage
    {
        return BlobStorage::fromSetting($settings->text(self::SETTING_BACKEND, BlobStorage::default()->value));
    }

    /**
     * @param iterable<string> $plaintextChunks pieces of any size
     */
    public function store(iterable $plaintextChunks, BlobMeta $meta, ?BlobStorage $storage = null): Blob
    {
        $storage ??= $this->defaultStorage;
        $key = FileKey::generate();
        $writer = BlobCipher::writer($key);

        $id = $this->repository->insertDraft(
            $storage,
            $storage === BlobStorage::Fs ? FsBlobBackend::newName() : null,
            $this->crypto->encrypt($key->raw()),
            $writer->header(),
            $meta,
        );
        // Read back instead of assembling the row here: the stored value is
        // the one that counts.
        $draft = $this->repository->find($id) ?? throw new BlobException('Blob row disappeared.');

        $backend = $this->backendFor($draft->storage);
        $sink = $backend->open($draft);
        $hash = hash_init('sha256');
        $size = 0;

        try {
            foreach ($plaintextChunks as $piece) {
                $size += strlen($piece);
                $this->emit($sink, $hash, $writer->write($piece));
            }
            $this->emit($sink, $hash, $writer->finish());
            $sink->commit();

            $cipherSha256 = hash_final($hash, true);
            $this->repository->complete($draft->id, $size, $cipherSha256);
        } catch (\Throwable $e) {
            // Whatever went wrong, nothing half-written stays: the temporary
            // file, the committed content and the draft row all go.
            $sink->discard();
            $backend->delete($draft);
            $this->repository->delete($draft->id);

            throw $e;
        }

        return new Blob(
            id: $draft->id,
            storage: $draft->storage,
            fsName: $draft->fsName,
            size: $size,
            cipherSha256: $cipherSha256,
            keyEnc: $draft->keyEnc,
            header: $draft->header,
            mimeType: $draft->mimeType,
            originalName: $draft->originalName,
            createdAt: $draft->createdAt,
        );
    }

    /**
     * @param resource $stream an open, readable stream - stays the caller's to close
     */
    public function storeStream($stream, BlobMeta $meta, ?BlobStorage $storage = null): Blob
    {
        return $this->store(self::fromStream($stream), $meta, $storage);
    }

    public function storeString(string $content, BlobMeta $meta, ?BlobStorage $storage = null): Blob
    {
        return $this->store([$content], $meta, $storage);
    }

    public function find(int $id): ?Blob
    {
        return $this->repository->find($id);
    }

    /**
     * Plaintext in pieces, for a download or a processing step. A stream
     * that was cut short, reordered or written under another key throws
     * {@see \App\Service\Crypto\CryptoException} - never a shorter file.
     * Since this is a generator, the error surfaces while iterating, not at
     * the call.
     *
     * @return \Generator<string>
     */
    public function openRead(Blob $blob): \Generator
    {
        if (!$blob->isComplete()) {
            throw new BlobException(sprintf('Blob %d was never finished.', $blob->id));
        }

        $reader = BlobCipher::reader(
            FileKey::fromRaw($this->crypto->decrypt($blob->keyEnc)),
            $blob->header,
        );

        foreach ($this->backendFor($blob->storage)->read($blob) as $piece) {
            $plaintext = $reader->read($piece);
            if ($plaintext !== '') {
                yield $plaintext;
            }
        }

        $last = $reader->finish();
        if ($last !== '') {
            yield $last;
        }
    }

    /**
     * The raw ciphertext, without the key - what a backup copies.
     *
     * @return iterable<string>
     */
    public function readCipher(Blob $blob): iterable
    {
        return $this->backendFor($blob->storage)->read($blob);
    }

    /**
     * Compares the stored ciphertext against its checksum. Needs no key: it
     * verifies bytes, it does not read the file.
     */
    public function verify(Blob $blob): bool
    {
        if (!$blob->isComplete()) {
            return false;
        }

        $hash = hash_init('sha256');
        try {
            foreach ($this->readCipher($blob) as $piece) {
                hash_update($hash, $piece);
            }
        } catch (BlobException) {
            // Gone from the shelf entirely - for the check that is the same
            // answer as a wrong checksum: this blob is not intact.
            return false;
        }

        return hash_equals((string) $blob->cipherSha256, hash_final($hash, true));
    }

    public function delete(Blob $blob): void
    {
        $this->backendFor($blob->storage)->delete($blob);
        $this->repository->delete($blob->id);
    }

    public function exists(Blob $blob): bool
    {
        return $this->backendFor($blob->storage)->exists($blob);
    }

    public function backendFor(BlobStorage $storage): BlobBackend
    {
        return match ($storage) {
            BlobStorage::Db => $this->dbBackend,
            BlobStorage::Fs => $this->fsBackend,
        };
    }

    /**
     * @param resource $stream
     *
     * @return \Generator<string>
     */
    private static function fromStream($stream): \Generator
    {
        while (!feof($stream)) {
            $piece = fread($stream, BlobCipher::CHUNK_BYTES);
            if ($piece === false) {
                throw new BlobException('Upload stream could not be read.');
            }
            if ($piece !== '') {
                yield $piece;
            }
        }
    }

    private function emit(BlobSink $sink, \HashContext $hash, string $ciphertext): void
    {
        if ($ciphertext === '') {
            return;
        }

        hash_update($hash, $ciphertext);
        $sink->write($ciphertext);
    }
}
