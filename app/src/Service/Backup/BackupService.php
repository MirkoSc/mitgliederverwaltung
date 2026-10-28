<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Dienste;

/**
 * The encrypted backups in shared/var/backups/ (docs/spec/04-installation-
 * und-update.md section 6): names, listing, rotation, and what goes into one.
 * Taken over from Vereinsbelege; made by the step chain BackupErstellung.
 *
 * A backup is a ZIP of dump.sql, the files of storage backend `fs` (blobs/),
 * the club logo (logo/) and manifest.json - encrypted as a whole with the
 * server key into `backup_<stamp>.zip.enc` (BackupCipher). The dump holds
 * the member data in the clear; only the encryption makes the file harmless.
 * config.php never goes in: without the separately kept key (the
 * "Sicherungsschlüssel") a backup cannot be read, which is the point.
 */
final readonly class BackupService
{
    /** Backups kept; older ones are deleted after every new one. */
    public const int KEEP = 10;

    /** How many backups may share one second (`_2`, `_3`, ...). */
    public const int MAX_PER_SECOND = 100;

    public const string ENDUNG = '.zip.enc';

    /** Prefix of the blob files inside the ZIP. */
    public const string BLOB_PREFIX = 'blobs/';

    /** Prefix of the logo files inside the ZIP. */
    public const string LOGO_PREFIX = 'logo/';

    /** A file name the logo directory may hold - nothing with a path in it. */
    public const string LOGO_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';

    /** The name pattern of a finished backup, for paths and the download route. */
    public const string NAME = '/^backup_\d{8}_\d{6}(_\d{1,3})?\.zip\.enc$/';

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock tests pin the time
     */
    public function __construct(
        private \PDO $pdo,
        private string $backupDir,
        private string $appVersion,
        private string $blobDir,
        private string $logoDir,
        private ?\Closure $clock = null,
    ) {
    }

    public static function aus(Dienste $d): self
    {
        return $d->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->pdo(),
            $d->paths->backupDir(),
            $d->version->value,
            $d->paths->blobDir(),
            $d->paths->logoDir(),
        ));
    }

    public function backupDir(): string
    {
        return $this->backupDir;
    }

    /**
     * Owner only, like shared/config.php: a work file holds the dump in the
     * clear for as long as the chain runs. Best effort - a host that does not
     * allow chmod keeps its default.
     */
    public static function nurFuerBesitzer(string $pfad): void
    {
        @chmod($pfad, 0600);
    }

    /**
     * The next free name for this second, taken atomically (fopen mode `x`),
     * so two backups at once cannot claim the same one.
     *
     * @return array{string, string} name and full path of the reserved, empty file
     */
    public function reserveName(\DateTimeImmutable $jetzt): array
    {
        $stempel = $jetzt->format('Ymd_His');
        for ($n = 1; $n <= self::MAX_PER_SECOND; $n++) {
            $name = $n === 1 ? sprintf('backup_%s%s', $stempel, self::ENDUNG) : sprintf('backup_%s_%d%s', $stempel, $n, self::ENDUNG);
            $pfad = $this->backupDir . '/' . $name;
            $handle = @fopen($pfad, 'x');
            if ($handle !== false) {
                fclose($handle);
                self::nurFuerBesitzer($pfad);

                return [$name, $pfad];
            }
            if (!is_file($pfad)) {
                throw new \RuntimeException('Das Backup-Verzeichnis ist nicht beschreibbar.');
            }
        }

        throw new \RuntimeException('Zu viele Backups in derselben Sekunde.');
    }

    /**
     * The files that travel besides the dump, sorted by entry name: the
     * finished ciphertext files of backend `fs` (blobs/<2 chars>/<id>, the
     * layout of Service\Storage\FsBlobBackend - a `.part` file is a write
     * that broke off) and the logo files.
     *
     * @param bool $mitBlobs false leaves the blob files out (the update's
     *        backup: an update never touches them)
     * @return array<string, string> ZIP entry name => path on disk
     */
    public function dateien(bool $mitBlobs = true): array
    {
        $ergebnis = [];
        if ($mitBlobs) {
            $blobs = glob($this->blobDir . '/*/*') ?: [];
            sort($blobs);
            foreach ($blobs as $pfad) {
                $name = basename($pfad);
                $unterordner = basename(dirname($pfad));
                if (preg_match('/^[0-9a-f]{32}$/', $name) !== 1 || $unterordner !== substr($name, 0, 2) || !is_file($pfad)) {
                    continue;
                }
                $ergebnis[self::BLOB_PREFIX . $unterordner . '/' . $name] = $pfad;
            }
        }

        $logos = glob($this->logoDir . '/*') ?: [];
        sort($logos);
        foreach ($logos as $pfad) {
            if (is_file($pfad) && preg_match(self::LOGO_NAME, basename($pfad)) === 1) {
                $ergebnis[self::LOGO_PREFIX . basename($pfad)] = $pfad;
            }
        }

        return $ergebnis;
    }

    /**
     * manifest.json - only versions and numbers, nothing about the club.
     */
    public function manifest(\DateTimeImmutable $jetzt, int $blobDateien, int $blobBytes, int $logoDateien): string
    {
        return json_encode([
            'app_version' => $this->appVersion,
            'schema_version' => $this->schemaVersion(),
            'erstellt_am' => $jetzt->format('Y-m-d H:i:s'),
            'blob_dateien' => $blobDateien,
            'blob_bytes' => $blobBytes,
            'logo_dateien' => $logoDateien,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * @return list<array{name: string, groesse: int, erstellt_am: \DateTimeImmutable}> newest first
     */
    public function list(): array
    {
        $backups = [];
        foreach (glob($this->backupDir . '/backup_*' . self::ENDUNG) ?: [] as $pfad) {
            $name = basename($pfad);
            $groesse = (int) filesize($pfad);
            // An empty file is a name reserved by a chain that is handing
            // over right now - not a backup.
            if (preg_match(self::NAME, $name) !== 1 || $groesse === 0) {
                continue;
            }
            $backups[] = [
                'name' => $name,
                'groesse' => $groesse,
                'erstellt_am' => self::zeitAusName($name) ?? new \DateTimeImmutable('@' . (int) filemtime($pfad)),
            ];
        }
        // Natural order: `_10` after `_9`, `backup_X` before `backup_X_2`.
        usort($backups, static fn(array $a, array $b): int => strnatcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * The path of a finished backup, or null - refuses anything but a plain
     * backup file name (no traversal).
     */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }
        $pfad = $this->backupDir . '/' . $name;

        return is_file($pfad) ? $pfad : null;
    }

    public function rotate(): int
    {
        $geloescht = 0;
        foreach (array_slice($this->list(), self::KEEP) as $alt) {
            if (@unlink($this->backupDir . '/' . $alt['name'])) {
                $geloescht++;
            }
        }

        return $geloescht;
    }

    public function jetzt(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
    }

    private static function zeitAusName(string $name): ?\DateTimeImmutable
    {
        if (preg_match('/^backup_(\d{8}_\d{6})/', $name, $treffer) !== 1) {
            return null;
        }
        $zeit = \DateTimeImmutable::createFromFormat('!Ymd_His', $treffer[1]);

        return $zeit === false ? null : $zeit;
    }

    private function schemaVersion(): int
    {
        try {
            return (int) $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        } catch (\PDOException) {
            // A broken instance is backed up all the same.
            return 0;
        }
    }
}
