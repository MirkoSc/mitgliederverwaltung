<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The run lock of the cron endpoint (docs/spec/01-plattform.md section 5).
 *
 * The host allows a cron job every minute, so a run that takes longer than
 * that would overlap with the next one. The lock is a database lock
 * (GET_LOCK) of the run's own connection: it is released by RELEASE_LOCK or,
 * if the run dies, by the database itself when the connection ends - a
 * crashed run can never park the cron. The name carries the database name,
 * because the lock namespace is that of the whole server.
 */
final class CronSperre
{
    private bool $gehalten = false;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** Takes the lock without waiting; false when another run holds it. */
    public function nehmen(): bool
    {
        $stmt = $this->pdo->prepare("SELECT GET_LOCK(CONCAT('mv_cron_', DATABASE()), 0)");
        $stmt->execute();

        return $this->gehalten = ((int) $stmt->fetchColumn()) === 1;
    }

    public function freigeben(): void
    {
        if (!$this->gehalten) {
            return;
        }
        $this->pdo->query("SELECT RELEASE_LOCK(CONCAT('mv_cron_', DATABASE()))")->fetchAll();
        $this->gehalten = false;
    }
}
