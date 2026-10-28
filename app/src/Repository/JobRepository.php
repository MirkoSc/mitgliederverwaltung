<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Job;
use App\Domain\JobSperre;
use App\Domain\JobStatus;

/**
 * The `job` table: step chains driven by the browser, one short request per
 * step (docs/spec/01-plattform.md section 4).
 *
 * A step runs like this: sperren() - do one idempotent piece of work -
 * schrittSpeichern() (or abschliessen() / fehlschlagen()) with the lock it
 * got. The lock expires on its own, so a step that died half way is simply
 * repeated by the next request; that is why every step must be idempotent.
 * A step that outlived its lock finds its write refused (false) - the
 * request that took over owns the job now.
 */
final readonly class JobRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function anlegen(string $typ, ?int $userId, array $state = [], ?\DateTimeImmutable $jetzt = null): int
    {
        $zeit = self::zeit($jetzt);
        $this->pdo->prepare(
            'INSERT INTO job (typ, status, user_id, state, progress, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, ?)',
        )->execute([$typ, JobStatus::Offen->value, $userId, self::json($state), $zeit, $zeit]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finde(int $id): ?Job
    {
        $stmt = $this->pdo->prepare('SELECT * FROM job WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::job($zeile) : null;
    }

    /**
     * Takes the job for one step, atomically: a lock only for the one request
     * that got it, null for everybody else. A finished or failed job is never
     * taken again.
     */
    public function sperren(int $id, int $sekunden, ?\DateTimeImmutable $jetzt = null): ?JobSperre
    {
        $jetzt ??= new \DateTimeImmutable();
        $bis = self::zeit($jetzt->modify(sprintf('+%d seconds', $sekunden)));
        $stmt = $this->pdo->prepare(
            'UPDATE job SET status = ?, locked_until = ?, updated_at = ?
             WHERE id = ? AND status IN (?, ?) AND (locked_until IS NULL OR locked_until <= ?)',
        );
        $stmt->execute([
            JobStatus::Laeuft->value,
            $bis,
            self::zeit($jetzt),
            $id,
            JobStatus::Offen->value,
            JobStatus::Laeuft->value,
            self::zeit($jetzt),
        ]);

        return $stmt->rowCount() === 1 ? new JobSperre($id, $bis) : null;
    }

    /**
     * Records the position after a step and releases the lock. False when
     * the lock was lost meanwhile - nothing is written then.
     *
     * @param array<string, mixed> $state
     */
    public function schrittSpeichern(JobSperre $sperre, array $state, int $progress, ?\DateTimeImmutable $jetzt = null): bool
    {
        return $this->schreiben(
            $sperre,
            'state = ?, progress = ?',
            [self::json($state), max(0, min(100, $progress))],
            $jetzt,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public function abschliessen(JobSperre $sperre, array $state, ?\DateTimeImmutable $jetzt = null): bool
    {
        return $this->schreiben(
            $sperre,
            'status = ?, state = ?, progress = 100',
            [JobStatus::Fertig->value, self::json($state)],
            $jetzt,
        );
    }

    /**
     * Ends the chain with an error. Only the exception class is kept: the
     * message of a database error quotes row data (CLAUDE.md section 4).
     */
    public function fehlschlagen(JobSperre $sperre, \Throwable $fehler, ?\DateTimeImmutable $jetzt = null): bool
    {
        return $this->schreiben(
            $sperre,
            'status = ?, last_error = ?',
            [JobStatus::Fehler->value, substr($fehler::class, 0, 255)],
            $jetzt,
        );
    }

    /**
     * One write under the lock: only while `locked_until` is still the value
     * this lock set, and releasing it in the same statement.
     *
     * @param list<string|int> $werte
     */
    private function schreiben(JobSperre $sperre, string $spalten, array $werte, ?\DateTimeImmutable $jetzt): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE job SET ' . $spalten . ', locked_until = NULL, updated_at = ? WHERE id = ? AND locked_until = ?',
        );
        $stmt->execute([...$werte, self::zeit($jetzt), $sperre->jobId, $sperre->bis]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $zeile
     */
    private static function job(array $zeile): Job
    {
        $state = $zeile['state'] === null ? [] : json_decode((string) $zeile['state'], true, flags: JSON_THROW_ON_ERROR);

        return new Job(
            id: (int) $zeile['id'],
            typ: (string) $zeile['typ'],
            status: JobStatus::from((string) $zeile['status']),
            userId: $zeile['user_id'] === null ? null : (int) $zeile['user_id'],
            state: is_array($state) ? $state : [],
            progress: (int) $zeile['progress'],
            lastError: $zeile['last_error'] === null ? null : (string) $zeile['last_error'],
            lockedUntil: $zeile['locked_until'] === null ? null : new \DateTimeImmutable((string) $zeile['locked_until']),
            createdAt: new \DateTimeImmutable((string) $zeile['created_at']),
            updatedAt: new \DateTimeImmutable((string) $zeile['updated_at']),
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function json(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Local time from PHP, not NOW() (the session may run in UTC). */
    private static function zeit(?\DateTimeImmutable $zeit): string
    {
        return ($zeit ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
