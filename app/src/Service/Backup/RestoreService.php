<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\SqlStatementStream;

/**
 * What a restore does with the decrypted backup ZIP - no Http, no session,
 * so it runs the same in a test as in the installer. Taken over from
 * Vereinsbelege; the step chain around it is Wiederherstellung.
 *
 * The ZIP is an upload and therefore untrusted, even after it decrypted
 * fine: nothing in it is ever executed or unpacked with extractTo(). Files
 * are written only under names this application builds itself
 * (blobs/<2 chars>/<32 hex>, logo/<plain name>).
 */
final readonly class RestoreService
{
    /** Statements per request - no single request may run long (CLAUDE.md section 1). */
    public const int STATEMENTS_PER_STEP = 200;

    /** ... and a byte budget on top: an INSERT of file_blob_chunk carries about 512 KiB. */
    public const int DUMP_BYTES_PER_STEP = 8 * 1024 * 1024;

    /** Files per request. */
    public const int DATEIEN_PER_STEP = 200;

    /** ... and a byte budget on top. */
    public const int DATEI_BYTES_PER_STEP = 16 * 1024 * 1024;

    private const string BLOB_ENTRY = '#^' . BackupService::BLOB_PREFIX . '([0-9a-f]{2})/([0-9a-f]{32})$#';

    /**
     * The "Sicherungsschlüssel" as typed or pasted: the server key in
     * base64, as config.php holds it and the installer shows it once
     * (04 section 2). Spaces and line breaks of a printout are ignored.
     *
     * @return string|null the raw 32-byte key, or null when it is none
     */
    public static function schluesselAusEingabe(#[\SensitiveParameter] string $eingabe): ?string
    {
        $roh = base64_decode((string) preg_replace('/\s+/', '', $eingabe), true);

        return $roh !== false && strlen($roh) === ServerCrypto::KEY_BYTES ? $roh : null;
    }

    /**
     * The `server_key` of an uploaded config.php - found with a regular
     * expression, never include(): the text is an upload.
     *
     * @return string|null the raw 32-byte key, or null when there is none
     */
    public static function schluesselAusConfig(#[\SensitiveParameter] string $config): ?string
    {
        if (preg_match("/['\"]server_key['\"]\\s*=>\\s*['\"]([A-Za-z0-9+\\/=]+)['\"]/", $config, $treffer) !== 1) {
            return null;
        }

        return self::schluesselAusEingabe($treffer[1]);
    }

    /**
     * Copies dump.sql out of the ZIP.
     *
     * @throws KeinBackup when the ZIP is not a usable backup
     */
    public function dumpAusZip(string $zipPfad, string $zielDatei): void
    {
        $zip = $this->open($zipPfad);
        try {
            $stream = $zip->getStream('dump.sql');
            if ($stream === false) {
                throw new KeinBackup('Das Backup ist unvollständig (dump.sql fehlt).');
            }
            $ziel = fopen($zielDatei, 'wb');
            if ($ziel === false) {
                fclose($stream);
                throw new \RuntimeException('Die Arbeitsdatei der Wiederherstellung ist nicht beschreibbar.');
            }
            @chmod($zielDatei, 0600);
            try {
                if (stream_copy_to_stream($stream, $ziel) === false) {
                    throw new \RuntimeException('dump.sql konnte nicht ausgepackt werden.');
                }
            } finally {
                fclose($stream);
                fclose($ziel);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestAusZip(string $zipPfad): array
    {
        $zip = $this->open($zipPfad);
        try {
            $json = $zip->getFromName('manifest.json');
        } finally {
            $zip->close();
        }
        $daten = $json === false ? null : json_decode($json, true);

        return is_array($daten) ? $daten : [];
    }

    /**
     * Runs the next block of dump.sql from byte `$offset` (a statement
     * boundary an earlier call returned, or 0).
     *
     * `$nachAnweisung` is called with the new boundary after every single
     * statement, so the caller can keep it right away. `$vielleichtSchonAngewendet`
     * says the step before broke off hard: then a duplicate key (1062) or an
     * existing table (1050) on the FIRST statement means it ran already, and
     * it is skipped. Safe because every statement of the dump is atomic.
     *
     * @param (\Closure(int): void)|null $nachAnweisung
     * @return array{offset: int, gesamt: int, anweisungen: int}
     */
    public function anwenden(
        \PDO $pdo,
        string $dumpDatei,
        int $offset,
        ?\Closure $nachAnweisung = null,
        bool $vielleichtSchonAngewendet = false,
    ): array {
        $stream = new SqlStatementStream($dumpDatei, $offset);

        // Every step is a request with a fresh connection, but the dump sets
        // its session variables once at the top (MysqlDumper).
        if ($offset > 0) {
            $pdo->exec(MysqlDumper::SESSION_NAMES);
            $pdo->exec(MysqlDumper::SESSION_FOREIGN_KEYS_OFF);
        }

        $anweisungen = 0;
        while ($anweisungen < self::STATEMENTS_PER_STEP && $stream->boundary() - $offset < self::DUMP_BYTES_PER_STEP) {
            $statement = $stream->next();
            if ($statement === null) {
                break;
            }
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                $code = $e->errorInfo[1] ?? null;
                if (!$vielleichtSchonAngewendet || $anweisungen > 0 || ($code !== 1062 && $code !== 1050)) {
                    throw $e;
                }
            }
            $anweisungen++;
            if ($nachAnweisung !== null) {
                $nachAnweisung($stream->boundary());
            }
        }

        if ($offset > 0 && $stream->boundary() >= $stream->size()) {
            $pdo->exec(MysqlDumper::SESSION_FOREIGN_KEYS_ON);
        }

        return ['offset' => $stream->boundary(), 'gesamt' => $stream->size(), 'anweisungen' => $anweisungen];
    }

    /**
     * How many file entries (blobs/, logo/) the backup carries - only the
     * central directory is read.
     */
    public function dateiAnzahl(string $zipPfad): int
    {
        $zip = $this->open($zipPfad);
        try {
            return count($this->dateiEintraege($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * Writes the next portion of files: blobs into `$blobDir`, logo files
     * into `$logoDir`. Every other name - a path with "..", an absolute
     * name, a stray file - is counted and dropped, never written.
     *
     * @return array{offset: int, gesamt: int, abgelehnt: int}
     */
    public function dateienAusZip(string $zipPfad, string $blobDir, string $logoDir, int $offset): array
    {
        $zip = $this->open($zipPfad);
        try {
            $eintraege = $this->dateiEintraege($zip);
            $gesamt = count($eintraege);
            $abgelehnt = 0;
            $bytes = 0;
            $getan = 0;

            for ($i = $offset; $i < $gesamt; $i++) {
                [$name, $groesse] = $eintraege[$i];
                $offset = $i + 1;

                $ziel = self::zielFuer($name, $blobDir, $logoDir);
                if ($ziel === null) {
                    $abgelehnt++;
                    continue;
                }
                $this->dateiSchreiben($zip, $name, $ziel);

                $getan++;
                $bytes += $groesse;
                if ($getan >= self::DATEIEN_PER_STEP || $bytes >= self::DATEI_BYTES_PER_STEP) {
                    break;
                }
            }

            return ['offset' => $offset, 'gesamt' => $gesamt, 'abgelehnt' => $abgelehnt];
        } finally {
            $zip->close();
        }
    }

    private static function zielFuer(string $name, string $blobDir, string $logoDir): ?string
    {
        if (preg_match(self::BLOB_ENTRY, $name, $treffer) === 1 && $treffer[1] === substr($treffer[2], 0, 2)) {
            return $blobDir . '/' . $treffer[1] . '/' . $treffer[2];
        }
        if (str_starts_with($name, BackupService::LOGO_PREFIX)) {
            $datei = substr($name, strlen(BackupService::LOGO_PREFIX));
            if (preg_match(BackupService::LOGO_NAME, $datei) === 1) {
                return $logoDir . '/' . $datei;
            }
        }

        return null;
    }

    /**
     * Every entry below blobs/ or logo/, in the order of the central
     * directory - the same order in every request, which makes the offset work.
     *
     * @return list<array{string, int}> name and size
     */
    private function dateiEintraege(\ZipArchive $zip): array
    {
        $eintraege = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string) $stat['name'];
            if (str_starts_with($name, BackupService::BLOB_PREFIX) || str_starts_with($name, BackupService::LOGO_PREFIX)) {
                $eintraege[] = [$name, (int) $stat['size']];
            }
        }

        return $eintraege;
    }

    private function dateiSchreiben(\ZipArchive $zip, string $eintrag, string $zielDatei): void
    {
        $dir = dirname($zielDatei);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Das Verzeichnis für die Dateien kann nicht angelegt werden.');
        }

        $quelle = $zip->getStream($eintrag);
        if ($quelle === false) {
            throw new \RuntimeException('Eine Datei im Backup ist nicht lesbar.');
        }
        // Written beside and renamed into place: a request that dies midway
        // leaves no half file under the real name.
        $tmp = $zielDatei . '.part';
        $ziel = fopen($tmp, 'wb');
        if ($ziel === false) {
            fclose($quelle);
            throw new \RuntimeException('Das Verzeichnis für die Dateien ist nicht beschreibbar.');
        }

        try {
            if (stream_copy_to_stream($quelle, $ziel) === false) {
                throw new \RuntimeException('Eine Datei im Backup ist nicht lesbar.');
            }
        } finally {
            fclose($quelle);
            fclose($ziel);
        }
        if (!@rename($tmp, $zielDatei)) {
            @unlink($tmp);
            throw new \RuntimeException('Eine Datei konnte nicht abgelegt werden.');
        }
        @chmod($zielDatei, 0664);
    }

    private function open(string $zipPfad): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPfad, \ZipArchive::RDONLY) !== true || $zip->locateName('dump.sql') === false) {
            throw new KeinBackup('Das Backup ist unvollständig (dump.sql fehlt).');
        }

        return $zip;
    }
}
