<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `setting` table (docs/spec/03-datenhaltung.md section 2): key/value,
 * the value stored as JSON, so a setting can be a string, a number, a flag
 * or a list. SQL lives in repositories only, prepared statements only.
 *
 * Settings are plaintext and part of every backup - nothing secret in here
 * unless it is server-key ciphertext.
 */
final readonly class SettingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The decoded value, or $default when the setting does not exist.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM setting WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    /** A string setting; $default when missing or not a string. */
    public function text(string $name, string $default = ''): string
    {
        $value = $this->get($name);

        return is_string($value) ? $value : $default;
    }

    /** An integer setting; $default when missing or not an integer. */
    public function zahl(string $name, int $default = 0): int
    {
        $value = $this->get($name);

        return is_int($value) ? $value : $default;
    }

    /** A boolean setting; $default when missing or not a boolean. */
    public function schalter(string $name, bool $default = false): bool
    {
        $value = $this->get($name);

        return is_bool($value) ? $value : $default;
    }

    public function set(string $name, mixed $value): void
    {
        // Timestamp from PHP, not NOW(): the database session may run in UTC
        // while the application convention is Europe/Berlin (bootstrap).
        $stmt = $this->pdo->prepare(
            'INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
        );
        $stmt->execute([
            $name,
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $name): void
    {
        $this->pdo->prepare('DELETE FROM setting WHERE name = ?')->execute([$name]);
    }
}
