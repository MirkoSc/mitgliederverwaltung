<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Config\Paths;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobCipher;

/**
 * Restoring an encrypted backup as a step chain (docs/spec/04-installation-
 * und-update.md section 6) - the building block the installer (issue #3)
 * drives, one short request per step:
 *
 *   entschluesseln  `.zip.enc` into a plaintext work ZIP, in portions
 *                   (BackupCipher; a wrong key fails in starten())
 *   auspacken       checks the ZIP, copies dump.sql out, reads the manifest
 *   dump            runs dump.sql in blocks, by byte offset
 *   dateien         blob files and logo, in portions
 *   migrationen     every migration the backup does not have yet (03 section 4)
 *   fertig          the plaintext work files are deleted
 *
 * State lives in a status file in the work directory, not in the database
 * (there is none yet, or it is being replaced) and not in the session, which
 * the installer may lose. The key is never stored: the caller passes it to
 * every step (the installer keeps it in its session until it writes
 * config.php), and the secretstream state in the status file is encrypted
 * under it - so the status file is useless without the key, and a step with
 * a different key fails.
 *
 * Every step is idempotent: the decryption cuts its target back to the
 * committed length, the dump resumes behind the last confirmed statement
 * (a statement that may have run before the request died is recognised by
 * RestoreService::anwenden()), files are written under their final name by
 * rename.
 */
final readonly class Wiederherstellung
{
    public const string PHASE_ENTSCHLUESSELN = 'entschluesseln';
    public const string PHASE_AUSPACKEN = 'auspacken';
    public const string PHASE_DUMP = 'dump';
    public const string PHASE_DATEIEN = 'dateien';
    public const string PHASE_MIGRATIONEN = 'migrationen';
    public const string PHASE_FERTIG = 'fertig';

    private const string STATUS = 'wiederherstellung.json';
    private const string SPERRE = 'wiederherstellung.lock';

    public function __construct(
        private string $arbeitsDir,
        private string $blobDir,
        private string $logoDir,
        private string $migrationsDir,
        private RestoreService $restore = new RestoreService(),
        private int $entschluesselnBytes = BackupCipher::BYTES_JE_SCHRITT,
    ) {
    }

    /** The usual places: work files in shared/var/backups/wiederherstellung/. */
    public static function fuer(Paths $paths): self
    {
        return new self(
            $paths->backupDir() . '/wiederherstellung',
            $paths->blobDir(),
            $paths->logoDir(),
            $paths->migrationsDir(),
        );
    }

    /**
     * Starts a restore of `$backupDatei` (an encrypted backup; the caller
     * keeps it until the chain is through). Checks file and key right away.
     *
     * @param string $schluessel the raw server key the backup was made with
     * @return array<string, mixed>
     *
     * @throws KeinBackup
     * @throws FalscherSchluessel
     */
    public function starten(string $backupDatei, #[\SensitiveParameter] string $schluessel): array
    {
        BackupCipher::pruefen($backupDatei, new ServerCrypto($schluessel));

        return $this->gesperrt(function () use ($backupDatei): array {
            $this->arbeitsdateienLoeschen();
            $stand = [
                'id' => bin2hex(random_bytes(8)),
                'phase' => self::PHASE_ENTSCHLUESSELN,
                'backup' => $backupDatei,
                'entschluesselung' => null,
                'backup_bytes' => (int) filesize($backupDatei),
                'manifest' => [],
                'dump_offset' => 0,
                'dump_gesamt' => 0,
                'dump_laeuft' => false,
                'dateien_offset' => 0,
                'dateien_gesamt' => 0,
                'abgelehnt' => 0,
                'migrationen' => 0,
            ];
            $this->speichern($stand);

            return $stand;
        });
    }

    /**
     * @return array<string, mixed>|null the running or last restore
     */
    public function stand(): ?array
    {
        $pfad = $this->arbeitsDir . '/' . self::STATUS;
        if (!is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);

        return is_array($daten) && isset($daten['id'], $daten['phase']) ? $daten : null;
    }

    /**
     * Runs the next step.
     *
     * @param \PDO $pdo the connection to the database being restored into
     * @param string $schluessel the same raw server key as in starten()
     * @return array<string, mixed> the state after the step; `phase` is
     *         PHASE_FERTIG once the restore is complete
     */
    public function schritt(\PDO $pdo, #[\SensitiveParameter] string $schluessel): array
    {
        $crypto = new ServerCrypto($schluessel);

        return $this->gesperrt(function () use ($pdo, $crypto): array {
            $stand = $this->stand();
            if ($stand === null) {
                throw new \RuntimeException('Es läuft keine Wiederherstellung.');
            }

            $stand = match ($stand['phase']) {
                self::PHASE_ENTSCHLUESSELN => $this->entschluesseln($stand, $crypto),
                self::PHASE_AUSPACKEN => $this->auspacken($stand),
                self::PHASE_DUMP => $this->dump($stand, $pdo),
                self::PHASE_DATEIEN => $this->dateien($stand),
                self::PHASE_MIGRATIONEN => $this->migrationen($stand, $pdo),
                self::PHASE_FERTIG => $stand,
                default => throw new \RuntimeException('Unbekannter Schritt der Wiederherstellung.'),
            };
            if ($stand['phase'] === self::PHASE_FERTIG) {
                $this->arbeitsdateienLoeschen();
            }
            $this->speichern($stand);

            return $stand;
        });
    }

    /** Discards the restore and its plaintext work files. */
    public function abbrechen(): void
    {
        $this->gesperrt(function (): null {
            $this->arbeitsdateienLoeschen();
            $status = $this->arbeitsDir . '/' . self::STATUS;
            if (is_file($status)) {
                unlink($status);
            }

            return null;
        });
    }

    /**
     * How far the chain is, 0 to 100 - for a progress bar.
     *
     * @param array<string, mixed> $stand
     */
    public static function fortschritt(array $stand): int
    {
        $anteil = static fn(int|float $teil, int|float $ganz): float => $ganz > 0 ? min(1.0, $teil / $ganz) : 1.0;

        return (int) round(100 * match ($stand['phase'] ?? '') {
            self::PHASE_ENTSCHLUESSELN => 0.3 * $anteil(
                (int) ($stand['entschluesselung']['block'] ?? 0) * BlobCipher::BLOCK_BYTES,
                (int) ($stand['backup_bytes'] ?? 0),
            ),
            self::PHASE_AUSPACKEN => 0.3,
            self::PHASE_DUMP => 0.3 + 0.5 * $anteil((int) $stand['dump_offset'], (int) $stand['dump_gesamt']),
            self::PHASE_DATEIEN => 0.8 + 0.15 * $anteil((int) $stand['dateien_offset'], (int) $stand['dateien_gesamt']),
            self::PHASE_MIGRATIONEN => 0.95,
            self::PHASE_FERTIG => 1.0,
            default => 0.0,
        });
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function entschluesseln(array $stand, ServerCrypto $crypto): array
    {
        $zip = $this->arbeitsdatei($stand, 'zip');
        if ($stand['entschluesselung'] !== null && !is_file($zip)) {
            throw new \RuntimeException('Die Arbeitsdatei der Wiederherstellung fehlt – bitte neu beginnen.');
        }
        $entschluesselung = BackupCipher::entschluesseln((string) $stand['backup'], $zip, $stand['entschluesselung'], $crypto, $this->entschluesselnBytes);

        return [
            ...$stand,
            'entschluesselung' => $entschluesselung['fertig'] ? null : $entschluesselung,
            'phase' => $entschluesselung['fertig'] ? self::PHASE_AUSPACKEN : self::PHASE_ENTSCHLUESSELN,
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function auspacken(array $stand): array
    {
        $zip = $this->arbeitsdatei($stand, 'zip');
        $dump = $this->arbeitsdatei($stand, 'sql');
        $this->restore->dumpAusZip($zip, $dump);
        $manifest = $this->restore->manifestAusZip($zip);

        return [
            ...$stand,
            'phase' => self::PHASE_DUMP,
            // Versions and numbers only (BackupService::manifest()).
            'manifest' => array_filter($manifest, static fn(mixed $wert): bool => is_scalar($wert)),
            'dump_offset' => 0,
            'dump_gesamt' => (int) filesize($dump),
            'dump_laeuft' => false,
            'dateien_gesamt' => $this->restore->dateiAnzahl($zip),
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function dump(array $stand, \PDO $pdo): array
    {
        $dump = $this->arbeitsdatei($stand, 'sql');
        $vielleicht = $stand['dump_laeuft'] === true;
        $this->speichern([...$stand, 'dump_laeuft' => true]);

        $ergebnis = $this->restore->anwenden(
            $pdo,
            $dump,
            (int) $stand['dump_offset'],
            function (int $offset) use (&$stand): void {
                // Kept after every statement: a request that dies midway
                // resumes behind the last confirmed one.
                $stand['dump_offset'] = $offset;
                $this->speichern([...$stand, 'dump_laeuft' => true]);
            },
            $vielleicht,
        );
        $fertig = $ergebnis['offset'] >= $ergebnis['gesamt'];

        return [
            ...$stand,
            'phase' => $fertig ? self::PHASE_DATEIEN : self::PHASE_DUMP,
            'dump_offset' => $ergebnis['offset'],
            'dump_laeuft' => false,
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function dateien(array $stand): array
    {
        if ((int) $stand['dateien_gesamt'] === 0) {
            return [...$stand, 'phase' => self::PHASE_MIGRATIONEN];
        }
        $ergebnis = $this->restore->dateienAusZip(
            $this->arbeitsdatei($stand, 'zip'),
            $this->blobDir,
            $this->logoDir,
            (int) $stand['dateien_offset'],
        );

        return [
            ...$stand,
            'phase' => $ergebnis['offset'] >= $ergebnis['gesamt'] ? self::PHASE_MIGRATIONEN : self::PHASE_DATEIEN,
            'dateien_offset' => $ergebnis['offset'],
            'abgelehnt' => (int) $stand['abgelehnt'] + $ergebnis['abgelehnt'],
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function migrationen(array $stand, \PDO $pdo): array
    {
        $ergebnis = new Migrator($pdo, $this->migrationsDir)->migrate();

        return [...$stand, 'phase' => self::PHASE_FERTIG, 'migrationen' => count($ergebnis->applied)];
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function arbeitsdatei(array $stand, string $endung): string
    {
        return sprintf('%s/wiederherstellung_%s.%s', $this->arbeitsDir, (string) $stand['id'], $endung);
    }

    private function arbeitsdateienLoeschen(): void
    {
        foreach (glob($this->arbeitsDir . '/wiederherstellung_*') ?: [] as $datei) {
            @unlink($datei);
        }
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function speichern(array $stand): void
    {
        $pfad = $this->arbeitsDir . '/' . self::STATUS;
        $tmp = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($stand, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $pfad)) {
            @unlink($tmp);
            throw new \RuntimeException('Der Stand der Wiederherstellung kann nicht gespeichert werden.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    private function gesperrt(\Closure $arbeit): mixed
    {
        if (!is_dir($this->arbeitsDir) && !@mkdir($this->arbeitsDir, 0700, true) && !is_dir($this->arbeitsDir)) {
            throw new \RuntimeException('Das Arbeitsverzeichnis der Wiederherstellung kann nicht angelegt werden.');
        }
        $sperre = fopen($this->arbeitsDir . '/' . self::SPERRE, 'c');
        if ($sperre === false) {
            throw new \RuntimeException('Das Arbeitsverzeichnis der Wiederherstellung ist nicht beschreibbar.');
        }
        try {
            if (!flock($sperre, LOCK_EX | LOCK_NB)) {
                throw new BackupLaeuftBereits('Ein Schritt der Wiederherstellung läuft gerade.');
            }

            return $arbeit();
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }
}
