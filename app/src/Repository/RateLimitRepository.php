<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `rate_limit` table (migrations/017_rate_limit.sql), taken over from
 * Vereinsbelege. One row per active key: the counter of a window, not a log
 * of attempts. The key arrives as a digest (App\Service\RateLimiter).
 */
final readonly class RateLimitRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Counts one attempt and returns the count THIS request produced - in
     * one statement, so parallel requests cannot all read "below the limit"
     * before any of them writes: a row whose window has run out starts over
     * at 1, a current one goes up by 1, a missing one is created.
     * LAST_INSERT_ID(expr) hands back the value this very statement wrote
     * (per connection, so a parallel request cannot shift it). MariaDB
     * applies the assignments left to right, which is why `count` (it
     * compares the OLD window) comes before `window_start`; GREATEST() keeps
     * a request with a slightly older clock from rolling a window back.
     */
    public function hit(string $keyHash, string $windowStart): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limit (key_hash, window_start, `count`) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE
                 `count` = LAST_INSERT_ID(IF(window_start < VALUES(window_start), 1, `count` + 1)),
                 window_start = GREATEST(window_start, VALUES(window_start))',
        );
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $windowStart);
        $stmt->execute();

        $zaehler = $this->pdo->query('SELECT LAST_INSERT_ID()');

        return $zaehler === false ? 0 : (int) $zaehler->fetchColumn();
    }

    public function delete(string $keyHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE key_hash = ?');
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /** @return int how many rows the sweep removed */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE window_start < ?');
        $stmt->execute([$cutoff->format(self::FORMAT)]);

        return $stmt->rowCount();
    }
}
