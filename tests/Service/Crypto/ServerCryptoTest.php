<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use PHPUnit\Framework\TestCase;

/**
 * The server key level (docs/spec/02-sicherheit.md section 2): file keys,
 * TOTP secrets and the SMTP password.
 */
final class ServerCryptoTest extends TestCase
{
    private const string PLAINTEXT = 'geheim-passwort-1234';

    private static function crypto(): ServerCrypto
    {
        return new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
    }

    public function testRoundTrip(): void
    {
        $crypto = self::crypto();

        self::assertSame(self::PLAINTEXT, $crypto->decrypt($crypto->encrypt(self::PLAINTEXT)));
    }

    public function testCiphertextRevealsNothingAboutThePlaintext(): void
    {
        $stored = self::crypto()->encrypt(self::PLAINTEXT);

        self::assertStringNotContainsString(self::PLAINTEXT, $stored);
        self::assertSame(ServerCrypto::VERSION, ord($stored[0]));
    }

    public function testSameValueEncryptsDifferentlyEveryTime(): void
    {
        $crypto = self::crypto();

        self::assertNotSame($crypto->encrypt(self::PLAINTEXT), $crypto->encrypt(self::PLAINTEXT));
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $stored = self::crypto()->encrypt(self::PLAINTEXT);

        $this->expectException(CryptoException::class);

        self::crypto()->decrypt($stored);
    }

    public function testManipulatedCiphertextFails(): void
    {
        $crypto = self::crypto();
        $stored = $crypto->encrypt(self::PLAINTEXT);
        $stored[strlen($stored) - 1] = chr(ord($stored[strlen($stored) - 1]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $crypto->decrypt($stored);
    }

    public function testUnknownFormatVersionIsReportedAsSuch(): void
    {
        $crypto = self::crypto();
        $stored = $crypto->encrypt(self::PLAINTEXT);
        $stored[0] = chr(ServerCrypto::VERSION + 1);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('unknown format version');

        $crypto->decrypt($stored);
    }

    public function testTruncatedValueFails(): void
    {
        $crypto = self::crypto();

        $this->expectException(CryptoException::class);

        $crypto->decrypt(substr($crypto->encrypt(self::PLAINTEXT), 0, 8));
    }

    public function testKeyOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);

        new ServerCrypto('zu kurz');
    }

    public function testDuringAKeyChangeBothKeysDecryptButOnlyTheNewOneEncrypts(): void
    {
        $alt = random_bytes(ServerCrypto::KEY_BYTES);
        $neu = random_bytes(ServerCrypto::KEY_BYTES);
        $unterAlt = new ServerCrypto($alt)->encrypt(self::PLAINTEXT);
        $ring = new ServerCrypto($neu, $alt);

        self::assertTrue($ring->wechselLaeuft());
        self::assertFalse(new ServerCrypto($neu)->wechselLaeuft());
        self::assertSame(self::PLAINTEXT, $ring->decrypt($unterAlt));
        self::assertFalse($ring->mitAktuellemSchluessel($unterAlt));

        $unterNeu = $ring->encrypt(self::PLAINTEXT);
        self::assertTrue($ring->mitAktuellemSchluessel($unterNeu));
        self::assertSame(self::PLAINTEXT, new ServerCrypto($neu)->decrypt($unterNeu));

        $this->expectException(CryptoException::class);
        new ServerCrypto($alt)->decrypt($unterNeu);
    }

    public function testNeitherKeyOpensAForeignValue(): void
    {
        $fremd = self::crypto()->encrypt(self::PLAINTEXT);
        $ring = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES), random_bytes(ServerCrypto::KEY_BYTES));

        self::assertFalse($ring->mitAktuellemSchluessel($fremd));
        self::assertFalse($ring->mitAktuellemSchluessel('kaputt'));
        $this->expectException(CryptoException::class);
        $ring->decrypt($fremd);
    }

    public function testThePreviousKeyOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);

        new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES), 'zu kurz');
    }

    public function testDebugOutputHidesTheKey(): void
    {
        $key = random_bytes(ServerCrypto::KEY_BYTES);

        $dump = print_r((new ServerCrypto($key))->__debugInfo(), true);

        self::assertStringNotContainsString($key, $dump);
    }
}
