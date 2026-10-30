<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Dienste;
use App\Config\ConfigException;
use App\Installer\ConfigWriter;
use App\Service\Crypto\ServerCrypto;

/**
 * "Backup jetzt erstellen" as a step chain of short requests (CLAUDE.md
 * section 1, docs/spec/04-installation-und-update.md section 6). Taken over
 * from Vereinsbelege, plus the encryption as a last phase:
 *
 *   dump           the database dump in parts (MysqlDumper::schritt()),
 *                  straight into the open ZIP entry dump.sql
 *   dateien        the blob files of backend `fs` and the logo, in portions
 *   abschluss      manifest.json and the central directory
 *   verschluesseln the finished ZIP into `.zip.enc` under the server key, in
 *                  portions (BackupCipher)
 *   fertig         handed over under a name from BackupService::reserveName()
 *
 * The plaintext ZIP exists only as a work file `erstellung_<id>.zip.part`
 * (0600, in shared/var/backups/ behind `Require all denied`) while the chain
 * runs, and is deleted the moment the encrypted file is in place - or when
 * the chain is cancelled, or by the cron once it is abandoned
 * (BackupAufraeumenTask). list() and path() never see a work file.
 *
 * State lives in `erstellung.json` next to the backups (not in the session:
 * an admin may continue in another tab; not in the `job` table: that table
 * is part of the dump being written), guarded by an flock so two tabs cannot
 * append at once. Every step first cuts the work files back to the lengths of
 * the last committed step, so a step that died halfway is simply run again.
 */
final readonly class BackupErstellung
{
    /** Files per step. */
    public const int PORTION_DATEIEN = 200;

    /** Bytes of files per step (at least one file). */
    public const int PORTION_BYTES = 16 * 1024 * 1024;

    /** Dump text per step. */
    public const int DUMP_BYTES = 32 * 1024 * 1024;

    /** Seconds of dumping per step - well inside max_execution_time = 30. */
    public const float DUMP_SEKUNDEN = 8.0;

    /** Marks a chain the update started (issue #4). */
    public const string ZWECK_UPDATE = 'update';

    /** A chain nobody has advanced for this long may be discarded. */
    public const int VERWAIST_NACH_SEKUNDEN = 3600;

    public const string PHASE_DUMP = 'dump';
    public const string PHASE_DATEIEN = 'dateien';
    public const string PHASE_ABSCHLUSS = 'abschluss';
    public const string PHASE_VERSCHLUESSELN = 'verschluesseln';
    public const string PHASE_FERTIG = 'fertig';

    private const string STATUS = 'erstellung.json';
    private const string SPERRE = 'erstellung.lock';

    public function __construct(
        private BackupService $backups,
        private \PDO $pdo,
        private ServerCrypto $crypto,
        private int $dumpBytes = self::DUMP_BYTES,
        private float $dumpSekunden = self::DUMP_SEKUNDEN,
        private int $verschluesselnBytes = BackupCipher::BYTES_JE_SCHRITT,
        /**
         * Whether the server key is being changed, read from config.php
         * itself (App\Service\Crypto\Schluesselwechsel) - the config of
         * this request may be older; null asks $crypto.
         *
         * @var (\Closure(): bool)|null
         */
        private ?\Closure $schluesselwechselLaeuft = null,
    ) {
    }

    public static function aus(Dienste $d): self
    {
        return $d->dienst(self::class, static fn(Dienste $d): self => new self(
            BackupService::aus($d),
            $d->pdo(),
            ServerCrypto::aus($d),
            schluesselwechselLaeuft: static function () use ($d): bool {
                try {
                    return ConfigWriter::schluesselLesen($d->paths->configFile())[1] !== null;
                } catch (ConfigException) {
                    return ServerCrypto::aus($d)->wechselLaeuft();
                }
            },
        ));
    }

    /**
     * The current or last chain, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function stand(): ?array
    {
        $pfad = $this->dir() . '/' . self::STATUS;
        if (!is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);

        return is_array($daten) && isset($daten['id'], $daten['phase']) ? $daten : null;
    }

    /** Whether a chain is running and was advanced recently. */
    public function laeuft(): bool
    {
        $stand = $this->stand();

        return $stand !== null && $stand['phase'] !== self::PHASE_FERTIG && !$this->istVerwaist($stand);
    }

    /**
     * Whether an unfinished chain was left alone long enough to be discarded.
     *
     * @param array<string, mixed> $stand
     */
    public function istVerwaist(array $stand): bool
    {
        return $this->backups->jetzt()->getTimestamp() - (int) $stand['aktualisiert'] >= self::VERWAIST_NACH_SEKUNDEN;
    }

    /**
     * Starts a new chain; nothing is written to the archive yet.
     *
     * @param bool $mitBlobs false leaves the blob files out (the update's backup)
     * @param string|null $zweck who started it (ZWECK_UPDATE)
     * @return array<string, mixed>
     *
     * @throws BackupLaeuftBereits when another chain is still running
     */
    public function starten(bool $mitBlobs = true, ?string $zweck = null): array
    {
        return $this->gesperrt(function () use ($mitBlobs, $zweck): array {
            if ($this->laeuft()) {
                throw new BackupLaeuftBereits('Es wird bereits ein Backup erstellt.');
            }
            // A dump taken while the key change wraps the values anew would
            // hold some under the old and some under the new key - no one
            // key could restore it (App\Service\Crypto\Schluesselwechsel).
            if ($this->schluesselwechselLaeuft !== null ? ($this->schluesselwechselLaeuft)() : $this->crypto->wechselLaeuft()) {
                throw new BackupLaeuftBereits('Gerade wird der Server-Schlüssel gewechselt. Bitte danach erneut versuchen.');
            }
            $this->arbeitsdateienLoeschen();

            $jetzt = $this->backups->jetzt();
            $stand = [
                'id' => bin2hex(random_bytes(8)),
                'phase' => self::PHASE_DUMP,
                'mit_blobs' => $mitBlobs,
                'zweck' => $zweck,
                'dump' => null,
                'dump_eintrag' => null,
                'dump_zeilen' => 0,
                'gestartet' => $jetzt->format('Y-m-d H:i:s'),
                'aktualisiert' => $jetzt->getTimestamp(),
                'offset' => 0,
                'zentral' => 0,
                'eintraege' => 0,
                'liste_offset' => 0,
                'blob_dateien' => 0,
                'blob_bytes' => 0,
                'logo_dateien' => 0,
                'dateien_gesamt' => 0,
                'zip_bytes' => 0,
                'verschluesselung' => null,
                'name' => null,
            ];
            $this->speichern($stand);

            return [...$stand, 'gerade_fertig' => false];
        });
    }

    /**
     * Runs $arbeit while no chain can start: under the lock of the chain,
     * and only when none is running. The key change writes config.php
     * this way, so no backup can begin between its look at the backups and
     * the new key.
     *
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     *
     * @throws BackupLaeuftBereits when a chain is running or holds the lock
     */
    public function ausschliesslich(\Closure $arbeit): mixed
    {
        return $this->gesperrt(function () use ($arbeit): mixed {
            if ($this->laeuft()) {
                throw new BackupLaeuftBereits('Es wird gerade ein Backup erstellt.');
            }

            return $arbeit();
        });
    }

    /**
     * Runs the next step of the running chain.
     *
     * @return array<string, mixed> the state after the step; `phase` is
     *         PHASE_FERTIG and `name` set once the backup exists.
     *         `gerade_fertig` is true only in the answer of the one step
     *         that finished it.
     */
    public function schritt(): array
    {
        return $this->gesperrt(function (): array {
            $stand = $this->stand();
            if ($stand === null) {
                throw new \RuntimeException('Es läuft keine Backup-Erstellung.');
            }
            if ($stand['phase'] === self::PHASE_FERTIG) {
                return [...$stand, 'gerade_fertig' => false];
            }
            if ($stand['phase'] === self::PHASE_VERSCHLUESSELN) {
                return $this->verschluesseln($stand);
            }

            $archivPfad = $this->arbeitsdatei($stand, 'zip.part');
            $zentralPfad = $this->arbeitsdatei($stand, 'zentral.part');
            // Once dump.sql is open the archive has committed bytes: a missing
            // work file must not be recreated empty and padded to the old length.
            $begonnen = $stand['phase'] !== self::PHASE_DUMP || is_array($stand['dump_eintrag'] ?? null);
            if ($begonnen && (!is_file($archivPfad) || !is_file($zentralPfad))) {
                throw new \RuntimeException('Die Arbeitsdatei des Backups fehlt – bitte abbrechen und neu starten.');
            }

            $archiv = $this->oeffnen($archivPfad, (int) $stand['offset']);
            $zentral = $this->oeffnen($zentralPfad, (int) $stand['zentral']);
            try {
                $zip = new ZipAnhang($archiv, $zentral, new \DateTimeImmutable((string) $stand['gestartet']));
                $stand = match ($stand['phase']) {
                    self::PHASE_DUMP => $this->dump($stand, $zip),
                    self::PHASE_DATEIEN => $this->dateien($stand, $zip),
                    self::PHASE_ABSCHLUSS => $this->abschluss($stand, $zip),
                    default => throw new \RuntimeException('Unbekannter Schritt der Backup-Erstellung.'),
                };
                $stand['offset'] = (int) ftell($archiv);
                $stand['zentral'] = (int) ftell($zentral);
            } finally {
                fclose($archiv);
                fclose($zentral);
            }

            $stand['aktualisiert'] = $this->backups->jetzt()->getTimestamp();
            $this->speichern($stand);

            return [...$stand, 'gerade_fertig' => false];
        });
    }

    /**
     * The whole chain in one go - for tests and anything without a request
     * time limit.
     *
     * @return string the name of the new backup
     */
    public function komplett(bool $mitBlobs = true): string
    {
        $stand = $this->starten($mitBlobs);
        while ($stand['phase'] !== self::PHASE_FERTIG) {
            $stand = $this->schritt();
        }

        return (string) $stand['name'];
    }

    /** Discards the running chain and its work files. */
    public function abbrechen(): void
    {
        $this->gesperrt(function (): null {
            $this->arbeitsdateienLoeschen();
            $status = $this->dir() . '/' . self::STATUS;
            if (is_file($status)) {
                unlink($status);
            }

            return null;
        });
    }

    /**
     * For the cron: discards a chain nobody has advanced for an hour - its
     * work file holds the dump in the clear and must not lie around.
     *
     * @return bool whether something was discarded
     */
    public function verwaistAufraeumen(): bool
    {
        if (!is_dir($this->dir())) {
            return false;
        }
        try {
            // Decided under the lock: a chain resumed a moment ago stays.
            return $this->gesperrt(function (): bool {
                $stand = $this->stand();
                $unfertig = $stand !== null && $stand['phase'] !== self::PHASE_FERTIG;
                $hatArbeitsdateien = (glob($this->dir() . '/erstellung_*.part') ?: []) !== [];
                if ((!$unfertig && !$hatArbeitsdateien) || ($unfertig && !$this->istVerwaist($stand))) {
                    return false;
                }
                $this->arbeitsdateienLoeschen();
                $status = $this->dir() . '/' . self::STATUS;
                if (is_file($status)) {
                    unlink($status);
                }

                return true;
            });
        } catch (BackupLaeuftBereits) {
            // A step is running right now after all - next run.
            return false;
        }
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function dump(array $stand, ZipAnhang $zip): array
    {
        $eintrag = is_array($stand['dump_eintrag'] ?? null)
            ? $stand['dump_eintrag']
            : $zip->eintragBeginnen('dump.sql', ZipAnhang::DEFLATE);
        $dumpStand = is_array($stand['dump'] ?? null) ? $stand['dump'] : null;

        $dumpPfad = $this->arbeitsdatei($stand, 'dump.part');
        try {
            $strom = fopen($dumpPfad, 'w+b');
            if ($strom === false) {
                throw new \RuntimeException('Das Backup-Verzeichnis ist nicht beschreibbar.');
            }
            BackupService::nurFuerBesitzer($dumpPfad);
            try {
                $dumpStand = new MysqlDumper($this->pdo)->schritt($strom, $dumpStand, $this->dumpBytes, $this->dumpSekunden);
                rewind($strom);
                $eintrag = $zip->eintragFortsetzen($eintrag, $strom, 1);
            } finally {
                fclose($strom);
            }
        } finally {
            if (is_file($dumpPfad)) {
                unlink($dumpPfad);
            }
        }

        if ($dumpStand['fertig'] !== true) {
            return [
                ...$stand,
                'dump' => $dumpStand,
                'dump_eintrag' => $eintrag,
                'dump_zeilen' => (int) $dumpStand['zeilen'],
            ];
        }
        $zip->eintragBeenden($eintrag);

        // The files to pack, fixed right after the dump: the rows in the dump
        // and this list belong together.
        $liste = '';
        $anzahl = 0;
        foreach ($this->backups->dateien(($stand['mit_blobs'] ?? true) !== false) as $zipName => $pfad) {
            $liste .= $zipName . "\t" . $pfad . "\n";
            $anzahl++;
        }
        if (file_put_contents($this->arbeitsdatei($stand, 'liste.part'), $liste) === false) {
            throw new \RuntimeException('Das Backup-Verzeichnis ist nicht beschreibbar.');
        }

        return [
            ...$stand,
            'phase' => self::PHASE_DATEIEN,
            'dump' => null,
            'dump_eintrag' => null,
            'dump_zeilen' => (int) $dumpStand['zeilen'],
            'eintraege' => 1,
            'liste_offset' => 0,
            'dateien_gesamt' => $anzahl,
        ];
    }

    /**
     * Appends the next portion of the files listed after the dump. A listed
     * file that is gone stops the chain rather than being left out: its row
     * is in the dump, and a backup that silently lacks a file must never be
     * reported as made.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function dateien(array $stand, ZipAnhang $zip): array
    {
        $liste = @fopen($this->arbeitsdatei($stand, 'liste.part'), 'rb');
        if ($liste === false) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups fehlt – bitte abbrechen und neu starten.');
        }
        $offset = (int) $stand['liste_offset'];
        $blobs = 0;
        $logos = 0;
        $bytes = 0;
        $fertig = false;
        try {
            if (fseek($liste, $offset) !== 0) {
                throw new \RuntimeException('Die Arbeitsdatei des Backups ist beschädigt.');
            }
            while (true) {
                $zeile = fgets($liste);
                if ($zeile === false) {
                    $fertig = true;
                    break;
                }
                $getan = $blobs + $logos;
                if ($getan >= self::PORTION_DATEIEN || ($getan > 0 && $bytes >= self::PORTION_BYTES)) {
                    break;
                }
                [$eintrag, $pfad] = explode("\t", rtrim($zeile, "\n"), 2) + [1 => ''];
                $quelle = is_file($pfad) ? @fopen($pfad, 'rb') : false;
                if ($quelle === false) {
                    throw new \RuntimeException(
                        'Eine Datei ist seit Beginn des Backups verschwunden oder nicht lesbar – bitte das Backup abbrechen und neu starten.',
                    );
                }
                try {
                    // Ciphertext does not compress; the logo is a small image.
                    $groesse = $zip->eintrag($eintrag, $quelle);
                } finally {
                    fclose($quelle);
                }
                if (str_starts_with($eintrag, BackupService::BLOB_PREFIX)) {
                    $blobs++;
                    $bytes += $groesse;
                } else {
                    $logos++;
                }
                $offset += strlen($zeile);
            }
        } finally {
            fclose($liste);
        }

        return [
            ...$stand,
            'phase' => $fertig ? self::PHASE_ABSCHLUSS : self::PHASE_DATEIEN,
            'liste_offset' => $offset,
            'eintraege' => (int) $stand['eintraege'] + $blobs + $logos,
            'blob_dateien' => (int) $stand['blob_dateien'] + $blobs,
            'blob_bytes' => (int) $stand['blob_bytes'] + $bytes,
            'logo_dateien' => (int) $stand['logo_dateien'] + $logos,
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function abschluss(array $stand, ZipAnhang $zip): array
    {
        $zip->eintragAusText('manifest.json', $this->backups->manifest(
            new \DateTimeImmutable((string) $stand['gestartet']),
            (int) $stand['blob_dateien'],
            (int) $stand['blob_bytes'],
            (int) $stand['logo_dateien'],
        ));
        $zip->abschliessen((int) $stand['eintraege'] + 1);

        return [...$stand, 'phase' => self::PHASE_VERSCHLUESSELN, 'eintraege' => (int) $stand['eintraege'] + 1];
    }

    /**
     * Encrypts the next portion of the finished ZIP. After the last one the
     * encrypted file is moved onto a freshly reserved name; the reserved name
     * is saved BEFORE the rename, so a request that dies right after it is
     * finished by the next step instead of reporting a missing work file.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function verschluesseln(array $stand): array
    {
        $zipPfad = $this->arbeitsdatei($stand, 'zip.part');
        $encPfad = $this->arbeitsdatei($stand, 'enc.part');

        $ziel = $stand['ziel'] ?? null;
        if (is_string($ziel) && !is_file($encPfad)) {
            $pfad = $this->backups->path($ziel);
            if ($pfad !== null && filesize($pfad) > 0) {
                return $this->abschliessen($stand, $ziel);
            }
        }
        if (!is_file($zipPfad) || ($stand['verschluesselung'] !== null && !is_file($encPfad))) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups fehlt – bitte abbrechen und neu starten.');
        }

        $verschluesselung = BackupCipher::verschluesseln($zipPfad, $encPfad, $stand['verschluesselung'], $this->crypto, $this->verschluesselnBytes);
        BackupService::nurFuerBesitzer($encPfad);
        $neu = [
            ...$stand,
            'verschluesselung' => $verschluesselung,
            'zip_bytes' => (int) filesize($zipPfad),
            'aktualisiert' => $this->backups->jetzt()->getTimestamp(),
        ];
        if (!$verschluesselung['fertig']) {
            $this->speichern($neu);

            return [...$neu, 'gerade_fertig' => false];
        }

        // An earlier attempt of the hand-over may have left its reserved,
        // still empty placeholder behind.
        if (is_string($ziel)) {
            $alt = $this->backups->path($ziel);
            if ($alt !== null && filesize($alt) === 0) {
                @unlink($alt);
            }
        }
        [$name, $zielPfad] = $this->backups->reserveName(new \DateTimeImmutable((string) $stand['gestartet']));
        $this->speichern([...$stand, 'ziel' => $name]);
        if (!@rename($encPfad, $zielPfad)) {
            @unlink($zielPfad);
            throw new \RuntimeException('Das fertige Backup konnte nicht abgelegt werden.');
        }
        BackupService::nurFuerBesitzer($zielPfad);

        return $this->abschliessen([...$neu, 'ziel' => $name], $name);
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function abschliessen(array $stand, string $name): array
    {
        $fertig = [
            ...$stand,
            'phase' => self::PHASE_FERTIG,
            'name' => $name,
            'verschluesselung' => null,
            'aktualisiert' => $this->backups->jetzt()->getTimestamp(),
        ];
        // The plaintext goes first, then the state says "done".
        $this->arbeitsdateienLoeschen();
        $this->speichern($fertig);
        try {
            $this->backups->rotate();
        } catch (\Throwable) {
            // The backup exists; the next one rotates again.
        }

        return [...$fertig, 'gerade_fertig' => true];
    }

    /**
     * @return resource opened for reading and writing, cut back to $laenge
     *         and positioned at its end
     */
    private function oeffnen(string $pfad, int $laenge)
    {
        $datei = fopen($pfad, 'c+b');
        if ($datei === false) {
            throw new \RuntimeException('Das Backup-Verzeichnis ist nicht beschreibbar.');
        }
        BackupService::nurFuerBesitzer($pfad);
        if (!ftruncate($datei, $laenge) || fseek($datei, $laenge) !== 0) {
            fclose($datei);
            throw new \RuntimeException('Die Arbeitsdatei des Backups ist beschädigt.');
        }

        return $datei;
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function arbeitsdatei(array $stand, string $endung): string
    {
        return sprintf('%s/erstellung_%s.%s', $this->dir(), (string) $stand['id'], $endung);
    }

    private function arbeitsdateienLoeschen(): void
    {
        foreach (glob($this->dir() . '/erstellung_*.part') ?: [] as $datei) {
            @unlink($datei);
        }
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function speichern(array $stand): void
    {
        $pfad = $this->dir() . '/' . self::STATUS;
        $tmp = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($stand, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $pfad)) {
            @unlink($tmp);
            throw new \RuntimeException('Der Stand des Backups kann nicht gespeichert werden.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    private function gesperrt(\Closure $arbeit): mixed
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Das Backup-Verzeichnis kann nicht angelegt werden.');
        }
        $sperre = fopen($dir . '/' . self::SPERRE, 'c');
        if ($sperre === false) {
            throw new \RuntimeException('Das Backup-Verzeichnis ist nicht beschreibbar.');
        }
        try {
            if (!flock($sperre, LOCK_EX | LOCK_NB)) {
                throw new BackupLaeuftBereits('Ein Schritt der Backup-Erstellung läuft gerade.');
            }

            return $arbeit();
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }

    private function dir(): string
    {
        return $this->backups->backupDir();
    }
}
