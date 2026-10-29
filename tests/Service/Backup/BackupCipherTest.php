<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupCipher;
use App\Service\Backup\FalscherSchluessel;
use App\Service\Backup\KeinBackup;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\FileKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Storage\BlobCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The encryption of a whole backup ZIP (docs/spec/02-sicherheit.md section 2,
 * Pflicht-Test "Backup verschlüsselt und nur mit dem Schlüssel lesbar"):
 * round trip in one go and in steps, nothing readable in the file, a wrong
 * key refused before anything is written, and a cut, altered or extended
 * file an error - never a shorter ZIP.
 */
final class BackupCipherTest extends TestCase
{
    private string $dir;

    private ServerCrypto $crypto;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mv_backupcipher_' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->crypto = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->dir);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function groessen(): array
    {
        $c = BlobCipher::CHUNK_BYTES;

        return [
            'empty' => [0],
            'one byte' => [1],
            'one block minus one' => [$c - 1],
            'exactly one block' => [$c],
            'one block plus one' => [$c + 1],
            'exactly three blocks' => [3 * $c],
            'several blocks and a rest' => [5 * $c + 123],
        ];
    }

    #[DataProvider('groessen')]
    public function testRoundTripInOneGo(int $groesse): void
    {
        $klartext = $this->klartext($groesse);

        BackupCipher::dateiVerschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $this->crypto);
        BackupCipher::dateiEntschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), $this->crypto);

        self::assertSame($klartext, file_get_contents($this->pfad('b.zip')));
        self::assertTrue(BackupCipher::istBackup($this->pfad('a.enc')));
        $bloecke = max(1, (int) ceil($groesse / BlobCipher::CHUNK_BYTES));
        self::assertSame(
            BackupCipher::KOPF_BYTES + $groesse + $bloecke * SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES,
            filesize($this->pfad('a.enc')),
        );
    }

    #[DataProvider('groessen')]
    public function testStepsGiveTheSameResultAsOneGo(int $groesse): void
    {
        $klartext = $this->klartext($groesse);

        $schritte = 0;
        $stand = null;
        do {
            $stand = BackupCipher::verschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $stand, $this->crypto, 1);
            $schritte++;
            // The position is JSON-safe, so a step chain can keep it in a file.
            $stand = json_decode(json_encode($stand, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } while (!$stand['fertig']);
        self::assertSame(max(1, (int) ceil($groesse / BlobCipher::CHUNK_BYTES)), $schritte);

        $stand = null;
        do {
            $stand = BackupCipher::entschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), $stand, $this->crypto, 1);
        } while (!$stand['fertig']);

        self::assertSame($klartext, file_get_contents($this->pfad('b.zip')));
    }

    public function testAStepRunAgainFromTheSamePositionWritesTheSameBytes(): void
    {
        $this->klartext(4 * BlobCipher::CHUNK_BYTES + 7);

        $erster = BackupCipher::verschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), null, $this->crypto, 1);
        $zweiter = BackupCipher::verschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $erster, $this->crypto, 1);
        $nachZweitem = (string) file_get_contents($this->pfad('a.enc'));

        // The request died after writing - the step runs again from $erster.
        $wiederholt = BackupCipher::verschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $erster, $this->crypto, 1);
        self::assertSame($nachZweitem, file_get_contents($this->pfad('a.enc')));
        self::assertSame($zweiter['block'], $wiederholt['block']);

        $stand = $wiederholt;
        while (!$stand['fertig']) {
            $stand = BackupCipher::verschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $stand, $this->crypto, 1);
        }
        BackupCipher::dateiEntschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), $this->crypto);
        self::assertSame(file_get_contents($this->pfad('a.zip')), file_get_contents($this->pfad('b.zip')));
    }

    public function testTheBlocksAreTheBlobFormat(): void
    {
        $klartext = $this->klartext(2 * BlobCipher::CHUNK_BYTES + 99);
        BackupCipher::dateiVerschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $this->crypto);

        $datei = (string) file_get_contents($this->pfad('a.enc'));
        $schluessel = FileKey::fromRaw($this->crypto->decrypt(substr($datei, 4, BackupCipher::KEY_ENC_BYTES)));
        $leser = BlobCipher::reader($schluessel, substr($datei, 4 + BackupCipher::KEY_ENC_BYTES, BlobCipher::HEADER_BYTES));
        $gelesen = $leser->read(substr($datei, BackupCipher::KOPF_BYTES)) . $leser->finish();

        self::assertSame($klartext, $gelesen);
    }

    public function testNothingOfThePlaintextIsInTheFile(): void
    {
        $zeile = "INSERT INTO `mitglied` VALUES ('Mustermann, Erika', 'DE02120300000000202051');\n";
        file_put_contents($this->pfad('a.zip'), "PK\x03\x04" . str_repeat($zeile, 3000));

        BackupCipher::dateiVerschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $this->crypto);
        $datei = (string) file_get_contents($this->pfad('a.enc'));

        self::assertStringNotContainsString('Mustermann', $datei);
        self::assertStringNotContainsString('DE02120300000000202051', $datei);
        self::assertStringNotContainsString("PK\x03\x04", $datei);
        // Not even a plain ZIP reader gets anything out of it.
        $zip = new \ZipArchive();
        self::assertNotTrue($zip->open($this->pfad('a.enc'), \ZipArchive::RDONLY));
    }

    public function testAWrongKeyIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->klartext(1000);
        BackupCipher::dateiVerschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $this->crypto);
        $andererSchluessel = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));

        try {
            BackupCipher::entschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), null, $andererSchluessel);
            self::fail('A wrong key must be refused.');
        } catch (FalscherSchluessel) {
        }
        self::assertFileDoesNotExist($this->pfad('b.zip'));

        $this->expectException(FalscherSchluessel::class);
        BackupCipher::pruefen($this->pfad('a.enc'), $andererSchluessel);
    }

    public function testAPositionKeptUnderOneKeyIsUselessUnderAnother(): void
    {
        $this->klartext(3 * BlobCipher::CHUNK_BYTES);
        BackupCipher::dateiVerschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $this->crypto);
        $stand = BackupCipher::entschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), null, $this->crypto, 1);

        $this->expectException(CryptoException::class);
        BackupCipher::entschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), $stand, new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES)), 1);
    }

    public function testAPlainZipIsNoBackup(): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->pfad('plain.zip'), \ZipArchive::CREATE);
        $zip->addFromString('dump.sql', 'SELECT 1;');
        $zip->close();

        self::assertFalse(BackupCipher::istBackup($this->pfad('plain.zip')));
        $this->expectException(KeinBackup::class);
        BackupCipher::pruefen($this->pfad('plain.zip'), $this->crypto);
    }

    /**
     * @return array<string, array{\Closure(string): string}>
     */
    public static function beschaedigungen(): array
    {
        $block = BlobCipher::BLOCK_BYTES;

        return [
            'last block cut off' => [static fn(string $d): string => substr($d, 0, BackupCipher::KOPF_BYTES + 3 * $block)],
            'cut inside a block' => [static fn(string $d): string => substr($d, 0, BackupCipher::KOPF_BYTES + $block + 100)],
            'only the head' => [static fn(string $d): string => substr($d, 0, BackupCipher::KOPF_BYTES)],
            'one byte flipped' => [static function (string $d) use ($block): string {
                $i = BackupCipher::KOPF_BYTES + $block + 10;
                $d[$i] = chr(ord($d[$i]) ^ 1);

                return $d;
            }],
            'two blocks swapped' => [static fn(string $d): string => substr($d, 0, BackupCipher::KOPF_BYTES)
                . substr($d, BackupCipher::KOPF_BYTES + $block, $block)
                . substr($d, BackupCipher::KOPF_BYTES, $block)
                . substr($d, BackupCipher::KOPF_BYTES + 2 * $block)],
            'data appended' => [static fn(string $d): string => $d . str_repeat("\0", $block)],
        ];
    }

    /**
     * @param \Closure(string): string $beschaedigen
     */
    #[DataProvider('beschaedigungen')]
    public function testADamagedFileIsAnErrorNeverAShorterZip(\Closure $beschaedigen): void
    {
        $this->klartext(3 * BlobCipher::CHUNK_BYTES + 500);
        BackupCipher::dateiVerschluesseln($this->pfad('a.zip'), $this->pfad('a.enc'), $this->crypto);
        file_put_contents($this->pfad('a.enc'), $beschaedigen((string) file_get_contents($this->pfad('a.enc'))));

        $this->expectException(CryptoException::class);
        BackupCipher::dateiEntschluesseln($this->pfad('a.enc'), $this->pfad('b.zip'), $this->crypto);
    }

    private function pfad(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    /** Writes a.zip with $groesse bytes of varying content and returns them. */
    private function klartext(int $groesse): string
    {
        $daten = '';
        $i = 0;
        while (strlen($daten) < $groesse) {
            $daten .= hash('sha256', (string) $i++, true);
        }
        $daten = substr($daten, 0, $groesse);
        file_put_contents($this->pfad('a.zip'), $daten);

        return $daten;
    }
}
