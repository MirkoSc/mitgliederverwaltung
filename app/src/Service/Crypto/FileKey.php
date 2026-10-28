<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The key of one blob (docs/spec/02-sicherheit.md section 2): random per
 * file, so that no two files share a key and a leaked file reveals nothing
 * about another.
 *
 * It never reaches the database as it is. What is stored in `file_blob.key_enc`
 * is {@see ServerCrypto::encrypt()} of it.
 */
final readonly class FileKey
{
    public const int BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

    private function __construct(private string $raw)
    {
    }

    public static function generate(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    public static function fromRaw(string $raw): self
    {
        if (strlen($raw) !== self::BYTES) {
            throw new CryptoException(sprintf('A file key is %d bytes long, got %d.', self::BYTES, strlen($raw)));
        }

        return new self($raw);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->raw, $other->raw);
    }

    /**
     * Keeps the key out of var_dump() output in a debug session.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['raw' => '*** file key ***'];
    }
}
