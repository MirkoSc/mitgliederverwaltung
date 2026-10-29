<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Dienste;

/**
 * The server key from shared/config.php, secretbox (docs/spec/02-sicherheit.md
 * section 2). It protects what the database must not hold in the clear:
 * the file keys of the blobs, the TOTP secrets and the SMTP password.
 *
 * The key sits in a file on the same server and never in the database or in
 * a database dump - that is its whole protection. Taken over from
 * Vereinsbelege without the blind index and the vault anchor: there is no
 * vault in this project (E-11).
 *
 * Stored form: version (1 byte) | nonce (24 bytes) | ciphertext+tag.
 */
final readonly class ServerCrypto
{
    public const int KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    public const int VERSION = 1;

    private const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private const int MAC_BYTES = SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    /**
     * @param string $key the raw server key, 32 bytes (Config::$serverKey)
     */
    public function __construct(private string $key)
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new CryptoException(sprintf('The server key is %d bytes long.', self::KEY_BYTES));
        }
    }

    public static function aus(Dienste $d): self
    {
        return $d->dienst(self::class, static fn(Dienste $d): self => new self($d->config->serverKey));
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return chr(self::VERSION) . $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    /**
     * @throws CryptoException on a wrong key or a manipulated value
     */
    public function decrypt(string $stored): string
    {
        if (strlen($stored) < 1 + self::NONCE_BYTES + self::MAC_BYTES) {
            throw new CryptoException('Server ciphertext is too short.');
        }

        $version = ord($stored[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('Server ciphertext has unknown format version %d.', $version));
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($stored, 1 + self::NONCE_BYTES),
            substr($stored, 1, self::NONCE_BYTES),
            $this->key,
        );

        if ($plaintext === false) {
            throw new CryptoException('Server ciphertext could not be decrypted.');
        }

        return $plaintext;
    }

    /**
     * A keyed hash (HMAC-SHA256, 32 raw bytes) for values only ever
     * compared, never read back - the codes and device tokens of the second
     * factor (App\Service\Account\ZweiterFaktor). Under a key derived
     * from the server key, so the secretbox key itself is not reused for a
     * second primitive.
     */
    public function hmac(#[\SensitiveParameter] string $wert): string
    {
        return hash_hmac('sha256', $wert, hash_hmac('sha256', 'hmac', $this->key, true), true);
    }

    /**
     * Keeps the key out of var_dump() output in a debug session.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '*** server key ***'];
    }
}
