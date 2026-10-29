<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\FileKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Storage\BlobCipher;

/**
 * The encryption of a whole backup ZIP with the server key
 * (docs/spec/02-sicherheit.md section 2, 04 section 6): the format of a blob,
 * with the wrapped file key in front, so that the file carries everything
 * but the server key itself.
 *
 *     magic "MVBK" (4) | ServerCrypto::encrypt(file key) (73)
 *       | BlobCipher header = version (1) + secretstream header (24)
 *       | blocks of 64 KiB plaintext + 17 bytes, the last one shorter
 *         (at least one byte unless the ZIP is empty) and carrying TAG_FINAL
 *
 * The blocks are exactly what Service\Storage\BlobStreamWriter produces, so a
 * BlobStreamReader reads them too. What this class adds is the step chain:
 * no request may run long (CLAUDE.md section 1), and a backup with all proof
 * scans can be gigabytes. Both directions therefore work on files in
 * portions and hand back a JSON-safe position:
 *
 *     ['block' => blocks done, 'zustand' => secretstream state, 'fertig' => bool]
 *
 * The secretstream state is the running key material of the stream. It
 * travels only encrypted under the server key, so a status file left lying
 * around reveals nothing the backup does not.
 *
 * A step that is run again from the same position writes the same bytes at
 * the same place: the target is cut back to where that position ends before
 * anything is written. Pushing is deterministic once the stream is
 * initialised.
 */
final class BackupCipher
{
    public const string MAGIC = 'MVBK';

    /** Length of ServerCrypto::encrypt() of a 32-byte file key. */
    public const int KEY_ENC_BYTES = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + FileKey::BYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    /** Everything in front of the first block. */
    public const int KOPF_BYTES = 4 + self::KEY_ENC_BYTES + BlobCipher::HEADER_BYTES;

    /** Plaintext per step - a few seconds of disk and sodium on a shared host. */
    public const int BYTES_JE_SCHRITT = 64 * 1024 * 1024;

    private const int TAG_MESSAGE = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

    private const int TAG_FINAL = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

    /**
     * Whether a file starts like an encrypted backup of this application -
     * the cheap check before the key is even tried.
     */
    public static function istBackup(string $pfad): bool
    {
        $datei = @fopen($pfad, 'rb');
        if ($datei === false) {
            return false;
        }
        try {
            $kopf = fread($datei, self::KOPF_BYTES);
        } finally {
            fclose($datei);
        }

        return is_string($kopf) && strlen($kopf) === self::KOPF_BYTES && str_starts_with($kopf, self::MAGIC);
    }

    /**
     * Encrypts the next portion of `$quelle` (the finished plaintext ZIP)
     * into `$ziel`. `$stand` is null for the start: then the head is written
     * and a fresh file key drawn.
     *
     * @param array{block: int, zustand: string, fertig: bool}|null $stand
     * @return array{block: int, zustand: string, fertig: bool}
     */
    public static function verschluesseln(
        string $quelle,
        string $ziel,
        ?array $stand,
        ServerCrypto $crypto,
        int $maxBytes = self::BYTES_JE_SCHRITT,
    ): array {
        $groesse = self::groesse($quelle);
        $bloecke = max(1, intdiv($groesse + BlobCipher::CHUNK_BYTES - 1, BlobCipher::CHUNK_BYTES));

        $aus = self::oeffnen($ziel);
        try {
            if ($stand === null) {
                $schluessel = FileKey::generate();
                [$zustand, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($schluessel->raw());
                self::abschneiden($aus, 0);
                self::schreibe($aus, self::MAGIC . $crypto->encrypt($schluessel->raw()) . chr(BlobCipher::VERSION) . $header);
                $stand = ['block' => 0, 'zustand' => self::zustandVerpacken($crypto, $zustand), 'fertig' => false];
            }
            if ($stand['fertig']) {
                return $stand;
            }

            $block = $stand['block'];
            $zustand = self::zustandAuspacken($crypto, $stand['zustand']);
            self::abschneiden($aus, self::KOPF_BYTES + $block * BlobCipher::BLOCK_BYTES);

            $ein = self::lesen($quelle);
            try {
                if (fseek($ein, $block * BlobCipher::CHUNK_BYTES) !== 0) {
                    throw new \RuntimeException('Das Backup konnte nicht gelesen werden.');
                }
                $geschrieben = 0;
                while ($block < $bloecke && ($geschrieben === 0 || $geschrieben < $maxBytes)) {
                    $daten = self::liesGenau($ein, $block === $bloecke - 1 ? $groesse - $block * BlobCipher::CHUNK_BYTES : BlobCipher::CHUNK_BYTES);
                    $tag = $block === $bloecke - 1 ? self::TAG_FINAL : self::TAG_MESSAGE;
                    self::schreibe($aus, sodium_crypto_secretstream_xchacha20poly1305_push($zustand, $daten, '', $tag));
                    $geschrieben += strlen($daten);
                    $block++;
                }
            } finally {
                fclose($ein);
            }
            if (!fflush($aus)) {
                throw new \RuntimeException('Das Backup konnte nicht geschrieben werden (Speicher voll?).');
            }

            return [
                'block' => $block,
                'zustand' => self::zustandVerpacken($crypto, $zustand),
                'fertig' => $block >= $bloecke,
            ];
        } finally {
            fclose($aus);
        }
    }

    /**
     * Decrypts the next portion of the encrypted backup `$quelle` into the
     * plaintext ZIP `$ziel`. With `$stand` null the head is checked first:
     * a file that is no backup, or a key it was not made with, fails right
     * there, before a single byte is written.
     *
     * @param array{block: int, zustand: string, fertig: bool}|null $stand
     * @return array{block: int, zustand: string, fertig: bool}
     *
     * @throws KeinBackup when the file is not an encrypted backup
     * @throws FalscherSchluessel when the key does not open it
     * @throws CryptoException when the file was cut short or tampered with
     */
    public static function entschluesseln(
        string $quelle,
        string $ziel,
        ?array $stand,
        ServerCrypto $crypto,
        int $maxBytes = self::BYTES_JE_SCHRITT,
    ): array {
        if ($stand === null) {
            $stand = ['block' => 0, 'zustand' => self::zustandVerpacken($crypto, self::kopfPruefen($quelle, $crypto)), 'fertig' => false];
        }
        if ($stand['fertig']) {
            return $stand;
        }

        $groesse = self::groesse($quelle);
        $block = $stand['block'];
        $zustand = self::zustandAuspacken($crypto, $stand['zustand']);

        $ein = self::lesen($quelle);
        $aus = self::oeffnen($ziel);
        try {
            self::abschneiden($aus, $block * BlobCipher::CHUNK_BYTES);
            if (fseek($ein, self::KOPF_BYTES + $block * BlobCipher::BLOCK_BYTES) !== 0) {
                throw new \RuntimeException('Das Backup konnte nicht gelesen werden.');
            }
            $gelesen = 0;
            $fertig = false;
            while (!$fertig && ($gelesen === 0 || $gelesen < $maxBytes)) {
                $rest = $groesse - self::KOPF_BYTES - $block * BlobCipher::BLOCK_BYTES;
                if ($rest <= 0) {
                    throw new CryptoException('Das Backup ist unvollständig (abgeschnitten).');
                }
                $chiffrat = self::liesGenau($ein, min($rest, BlobCipher::BLOCK_BYTES));
                $ergebnis = sodium_crypto_secretstream_xchacha20poly1305_pull($zustand, $chiffrat);
                if ($ergebnis === false) {
                    throw new CryptoException('Das Backup ist beschädigt oder wurde verändert.');
                }
                [$klartext, $tag] = $ergebnis;
                $block++;
                $gelesen += strlen($chiffrat);
                $letzter = $rest <= BlobCipher::BLOCK_BYTES;
                if ($tag === self::TAG_FINAL) {
                    if (!$letzter) {
                        throw new CryptoException('Das Backup ist beschädigt (Daten hinter dem Ende).');
                    }
                    $fertig = true;
                } elseif ($letzter) {
                    throw new CryptoException('Das Backup ist unvollständig (abgeschnitten).');
                }
                self::schreibe($aus, $klartext);
            }
            if (!fflush($aus)) {
                throw new \RuntimeException('Die Arbeitsdatei konnte nicht geschrieben werden (Speicher voll?).');
            }
        } finally {
            fclose($ein);
            fclose($aus);
        }

        return ['block' => $block, 'zustand' => self::zustandVerpacken($crypto, $zustand), 'fertig' => $fertig];
    }

    /**
     * The whole file in one go - for tests and anything without a time
     * limit; the step chains call the methods above.
     */
    public static function dateiVerschluesseln(string $quelle, string $ziel, ServerCrypto $crypto): void
    {
        $stand = null;
        do {
            $stand = self::verschluesseln($quelle, $ziel, $stand, $crypto, PHP_INT_MAX);
        } while (!$stand['fertig']);
    }

    /** {@see dateiVerschluesseln()} the other way round. */
    public static function dateiEntschluesseln(string $quelle, string $ziel, ServerCrypto $crypto): void
    {
        $stand = null;
        do {
            $stand = self::entschluesseln($quelle, $ziel, $stand, $crypto, PHP_INT_MAX);
        } while (!$stand['fertig']);
    }

    /**
     * Checks that `$quelle` is an encrypted backup the key opens - nothing
     * is decrypted beyond the head.
     *
     * @throws KeinBackup
     * @throws FalscherSchluessel
     */
    public static function pruefen(string $quelle, ServerCrypto $crypto): void
    {
        self::kopfPruefen($quelle, $crypto);
    }

    /**
     * Checks magic, unwraps the file key and opens the stream.
     *
     * @return string the initial secretstream state
     */
    private static function kopfPruefen(string $quelle, ServerCrypto $crypto): string
    {
        if (!self::istBackup($quelle)) {
            throw new KeinBackup('Die Datei ist kein verschlüsseltes Backup dieser Anwendung.');
        }
        $ein = self::lesen($quelle);
        try {
            $kopf = self::liesGenau($ein, self::KOPF_BYTES);
        } finally {
            fclose($ein);
        }

        try {
            $schluessel = FileKey::fromRaw($crypto->decrypt(substr($kopf, 4, self::KEY_ENC_BYTES)));
        } catch (CryptoException) {
            throw new FalscherSchluessel('Der Sicherungsschlüssel passt nicht zu diesem Backup.');
        }
        $header = substr($kopf, 4 + self::KEY_ENC_BYTES);
        if (ord($header[0]) !== BlobCipher::VERSION) {
            throw new KeinBackup('Das Backup hat ein unbekanntes Format.');
        }

        return sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 1), $schluessel->raw());
    }

    private static function zustandVerpacken(ServerCrypto $crypto, string $zustand): string
    {
        return base64_encode($crypto->encrypt($zustand));
    }

    private static function zustandAuspacken(ServerCrypto $crypto, string $verpackt): string
    {
        $roh = base64_decode($verpackt, true);
        if ($roh === false) {
            throw new CryptoException('Der Stand der Verschlüsselung ist beschädigt.');
        }

        return $crypto->decrypt($roh);
    }

    private static function groesse(string $pfad): int
    {
        clearstatcache(true, $pfad);
        $groesse = @filesize($pfad);
        if ($groesse === false) {
            throw new \RuntimeException('Die Datei des Backups fehlt.');
        }

        return $groesse;
    }

    /**
     * @return resource
     */
    private static function lesen(string $pfad)
    {
        $datei = @fopen($pfad, 'rb');
        if ($datei === false) {
            throw new \RuntimeException('Die Datei des Backups ist nicht lesbar.');
        }

        return $datei;
    }

    /**
     * @return resource opened for writing without truncating
     */
    private static function oeffnen(string $pfad)
    {
        $datei = @fopen($pfad, 'c+b');
        if ($datei === false) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups ist nicht beschreibbar.');
        }
        @chmod($pfad, 0600);

        return $datei;
    }

    /**
     * @param resource $datei
     */
    private static function abschneiden($datei, int $laenge): void
    {
        fseek($datei, 0, SEEK_END);
        $ist = (int) ftell($datei);
        if ($ist < $laenge) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups ist kürzer als ihr Stand – bitte neu beginnen.');
        }
        if (!ftruncate($datei, $laenge) || fseek($datei, $laenge) !== 0) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups ist beschädigt.');
        }
    }

    /**
     * @param resource $datei
     */
    private static function liesGenau($datei, int $laenge): string
    {
        $daten = '';
        while (strlen($daten) < $laenge) {
            $stueck = fread($datei, $laenge - strlen($daten));
            if ($stueck === false || $stueck === '') {
                throw new CryptoException('Das Backup ist unvollständig (abgeschnitten).');
            }
            $daten .= $stueck;
        }

        return $daten;
    }

    /**
     * @param resource $datei
     */
    private static function schreibe($datei, string $daten): void
    {
        $laenge = strlen($daten);
        $geschrieben = 0;
        while ($geschrieben < $laenge) {
            $n = fwrite($datei, $geschrieben === 0 ? $daten : substr($daten, $geschrieben));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Die Arbeitsdatei des Backups konnte nicht geschrieben werden (Speicher voll?).');
            }
            $geschrieben += $n;
        }
    }
}
