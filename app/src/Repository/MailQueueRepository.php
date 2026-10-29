<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MailStatus;
use App\Domain\QueuedMail;

/**
 * The `mail_queue` table (docs/spec/01-plattform.md section 6). SQL lives in
 * repositories only, prepared statements only.
 *
 * There is no separate lock column - see migrations/020_mail_queue.sql. A
 * claim is the same optimistic pattern as a job claim: a conditional UPDATE
 * whose row count says who won.
 */
final readonly class MailQueueRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * How long a claimed row is provisionally protected from being claimed
     * again before markSent()/markFailed() write the real outcome. Long
     * enough that a live SMTP conversation (15 s timeout) finishes first,
     * short enough that a crash does not park the mail for good.
     */
    private const int IN_FLIGHT_SECONDS = 300;

    public function __construct(private \PDO $pdo)
    {
    }

    public function enqueue(string $to, string $subject, string $body, ?\DateTimeImmutable $now = null): int
    {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_queue (to_address, subject, body, status, next_try_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$to, $subject, $body, MailStatus::Offen->value, $zeit, $zeit, $zeit]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?QueuedMail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mail_queue WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->fromRow($row);
    }

    /** Claims one specific row - the immediate attempt right after enqueue(). */
    public function claimById(int $id, \DateTimeImmutable $now): ?QueuedMail
    {
        return $this->attemptClaim($id, $now) ? $this->find($id) : null;
    }

    /**
     * Hands out due mails (`offen`, or `laeuft` whose provisional lock has
     * expired) and locks each one, oldest first.
     *
     * @return list<QueuedMail>
     */
    public function claimDue(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id FROM mail_queue WHERE status IN (?, ?) AND next_try_at <= ? ORDER BY id LIMIT %d',
            max(1, $limit),
        ));
        $stmt->execute([MailStatus::Offen->value, MailStatus::Laeuft->value, $now->format(self::FORMAT)]);

        $ergebnis = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if ($this->attemptClaim((int) $id, $now)) {
                $mail = $this->find((int) $id);
                if ($mail !== null) {
                    $ergebnis[] = $mail;
                }
            }
        }

        return $ergebnis;
    }

    /**
     * A sent mail keeps no body: it may hold an invitation or reset link or
     * a login code, which a reader of the database could still use.
     */
    public function markSent(int $id, ?\DateTimeImmutable $now = null): void
    {
        $this->pdo
            ->prepare('UPDATE mail_queue SET status = ?, body = \'\', last_error = NULL, updated_at = ? WHERE id = ?')
            ->execute([MailStatus::Gesendet->value, ($now ?? new \DateTimeImmutable())->format(self::FORMAT), $id]);
    }

    /**
     * Records a failed attempt. Takes the exception CLASS, not its message -
     * an SMTP or database message can quote the recipient or the credentials.
     * The caller (Mailer) decides whether this was the last attempt.
     */
    public function markFailed(
        int $id,
        string $errorClass,
        MailStatus $status,
        \DateTimeImmutable $naechsterVersuch,
        ?\DateTimeImmutable $now = null,
    ): void {
        // A mail that gave up for good is never sent again: its body (links,
        // codes) goes, the row stays for the count on the system page.
        $this->pdo
            ->prepare('UPDATE mail_queue SET status = ?, last_error = ?, next_try_at = ?, updated_at = ?'
                . ($status === MailStatus::Fehler ? ', body = \'\'' : '') . ' WHERE id = ?')
            ->execute([
                $status->value,
                mb_substr($errorClass, 0, 255),
                $naechsterVersuch->format(self::FORMAT),
                ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
                $id,
            ]);
    }

    /** How many mails gave up for good (status `fehler`) - for the system page. */
    public function anzahlFehler(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mail_queue WHERE status = ?');
        $stmt->execute([MailStatus::Fehler->value]);

        return (int) $stmt->fetchColumn();
    }

    /** Housekeeping for the cron (App\Service\Cron\MailCleanupTask). */
    public function deleteSentBefore(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM mail_queue WHERE status = ? AND updated_at < ?');
        $stmt->execute([MailStatus::Gesendet->value, $before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }

    private function attemptClaim(int $id, \DateTimeImmutable $now): bool
    {
        $jetzt = $now->format(self::FORMAT);
        $inFlightBis = $now->setTimestamp($now->getTimestamp() + self::IN_FLIGHT_SECONDS)->format(self::FORMAT);

        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue
             SET status = ?, attempts = attempts + 1, next_try_at = ?, updated_at = ?
             WHERE id = ? AND status IN (?, ?) AND next_try_at <= ?',
        );
        $stmt->execute([
            MailStatus::Laeuft->value,
            $inFlightBis,
            $jetzt,
            $id,
            MailStatus::Offen->value,
            MailStatus::Laeuft->value,
            $jetzt,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): QueuedMail
    {
        return new QueuedMail(
            id: (int) $row['id'],
            to: (string) $row['to_address'],
            subject: (string) $row['subject'],
            body: (string) $row['body'],
            status: MailStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            nextTryAt: new \DateTimeImmutable((string) $row['next_try_at']),
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
