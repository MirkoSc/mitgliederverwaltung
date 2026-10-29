<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `auth_token` table (migrations/016_auth_token.sql): one-time links
 * sent by mail. Only the digest of a token is ever stored or looked up
 * (App\Service\Account\EinmalLink). Taken over from Vereinsbelege.
 */
final readonly class AuthTokenRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(int $userId, string $typ, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_token (user_id, typ, token_hash, expires_at, used_at, created_at) VALUES (?, ?, ?, ?, NULL, ?)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $typ);
        $stmt->bindValue(3, $tokenHash, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $expiresAt->format(self::FORMAT));
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The row of a still usable token: right type, not used, not expired.
     * Every other case is the same null - neither the caller nor the
     * browser gains anything from knowing which it was.
     *
     * @return array{id: int, user_id: int}|null
     */
    public function findUsable(string $typ, string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM auth_token WHERE typ = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?',
        );
        $stmt->bindValue(1, $typ);
        $stmt->bindValue(2, $tokenHash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']] : null;
    }

    /**
     * Spends a token. `used_at IS NULL` makes this the actual one-time
     * check: of two requests racing with the same link only one gets true.
     */
    public function markUsed(int $id, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE auth_token SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $stmt->execute([$now->format(self::FORMAT), $id]);

        return $stmt->rowCount() === 1;
    }

    /** A new link replaces the previous one instead of opening a second door. */
    public function deleteForUser(int $userId, ?string $typ = null): void
    {
        if ($typ === null) {
            $stmt = $this->pdo->prepare('DELETE FROM auth_token WHERE user_id = ?');
            $stmt->execute([$userId]);

            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM auth_token WHERE user_id = ? AND typ = ?');
        $stmt->execute([$userId, $typ]);
    }

    /** Until when the account's open link of this type is good, or null. */
    public function gueltigBis(int $userId, string $typ, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(expires_at) FROM auth_token WHERE user_id = ? AND typ = ? AND used_at IS NULL AND expires_at > ?',
        );
        $stmt->execute([$userId, $typ, $now->format(self::FORMAT)]);
        $wert = $stmt->fetchColumn();

        return is_string($wert) ? new \DateTimeImmutable($wert) : null;
    }

    /** For the cron: links that ran out or were used are kept for nothing. */
    public function deleteExpired(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_token WHERE expires_at < ? OR used_at < ?');
        $stmt->execute([$before->format(self::FORMAT), $before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }
}
