<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\BlobMeta;
use App\Service\Storage\BlobService;
use App\Support\FileLogger;

/**
 * Where an uploaded import.csv waits between the requests of the first
 * import and of the reconciliation (docs/spec/10-import.md sections 1 and
 * 4): encrypted as a blob (server key), read back and checked on every use,
 * removed when the work is done or dropped.
 */
final readonly class ImportDateiAblage
{
    public function __construct(
        private \PDO $pdo,
        private BlobService $blobs,
        private FileLogger $logger,
    ) {
    }

    /** Stores the file; returns its blob id. */
    public function speichern(string $inhalt, string $dateiname): int
    {
        return $this->blobs->storeString($inhalt, new BlobMeta('text/csv', BlobMeta::bereinigterName($dateiname)))->id;
    }

    /** The file read and checked against the current master data; null when it is gone. */
    public function lesen(int $blobId): ?ImportDatei
    {
        $blob = $blobId > 0 ? $this->blobs->find($blobId) : null;
        if ($blob === null || !$blob->isComplete()) {
            return null;
        }
        try {
            $inhalt = '';
            foreach ($this->blobs->openRead($blob) as $stueck) {
                $inhalt .= $stueck;
            }
        } catch (\Throwable $fehler) {
            $this->logger->append('Import file could not be read: ' . $fehler::class);

            return null;
        }

        return ImportLeser::lesen($inhalt, ImportStammdaten::aus($this->pdo));
    }

    /** Removes a blob; a failure is logged, never thrown - an orphaned file is encrypted and harmless. */
    public function loeschen(int $blobId): void
    {
        if ($blobId <= 0) {
            return;
        }
        try {
            $blob = $this->blobs->find($blobId);
            if ($blob !== null) {
                $this->blobs->delete($blob);
            }
        } catch (\Throwable $fehler) {
            $this->logger->append(sprintf('File %d could not be removed: %s', $blobId, $fehler::class));
        }
    }
}
