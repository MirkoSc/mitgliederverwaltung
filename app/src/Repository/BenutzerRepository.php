<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Benutzer;
use App\Domain\BenutzerStatus;
use App\Domain\MfaMethode;
use App\Domain\Rolle;

/**
 * The `user` table (docs/spec/03-datenhaltung.md section 2). SQL lives in
 * repositories only, prepared statements only.
 *
 * Addresses arrive here already normalised (Benutzer::normalisiereEmail());
 * the repository does not guess.
 */
final readonly class BenutzerRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(
        string $email,
        string $name,
        string $passwordHash,
        BenutzerStatus $status,
        Rolle $rolle,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `user` (email, name, password_hash, status, rolle, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$email, $name, $passwordHash, $status->value, $rolle->value, $now->format(self::FORMAT)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Benutzer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `user` WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::ausZeile($row) : null;
    }

    public function findByEmail(string $email): ?Benutzer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `user` WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return is_array($row) ? self::ausZeile($row) : null;
    }

    /**
     * Every account, by name - the list of the settings section. A club has
     * a handful, no paging needed.
     *
     * @return list<Benutzer>
     */
    public function alle(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM `user` ORDER BY name, id');
        $alle = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $alle[] = self::ausZeile($row);
        }

        return $alle;
    }

    /** Whether there is any account at all - the installer's question (issue #3). */
    public function gibtEs(): bool
    {
        $stmt = $this->pdo->query('SELECT 1 FROM `user` LIMIT 1');

        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    /**
     * The ids of the active admins, their rows locked until the running
     * transaction ends - the "last admin" guard
     * (App\Service\Account\BenutzerVerwaltung).
     *
     * @return list<int>
     */
    public function sperreAktiveAdmins(): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM `user` WHERE rolle = ? AND status = ? ORDER BY id FOR UPDATE');
        $stmt->execute([Rolle::Admin->value, BenutzerStatus::Aktiv->value]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }

    public function updateStatus(int $id, BenutzerStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $id]);
    }

    public function updateBarrierefrei(int $id, bool $an): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET modus_barrierefrei = ? WHERE id = ?');
        $stmt->execute([$an ? 1 : 0, $id]);
    }

    public function updateRolle(int $id, Rolle $rolle): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET rolle = ? WHERE id = ?');
        $stmt->execute([$rolle->value, $id]);
    }

    /**
     * Ends every session of the account (App\Http\LoginGuard compares the
     * session's copy with this value).
     *
     * @return int the new value
     */
    public function bumpSessionEpoch(int $id): int
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET session_epoch = session_epoch + 1 WHERE id = ?');
        $stmt->execute([$id]);
        $stmt = $this->pdo->prepare('SELECT session_epoch FROM `user` WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    /** The second factor (App\Service\Account\ZweiterFaktor); null = not set up. */
    public function updateMfaMethode(int $id, ?MfaMethode $methode): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET mfa_method = ? WHERE id = ?');
        $stmt->execute([$methode?->value, $id]);
    }

    public function touchLastLogin(int $id, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET last_login_at = ? WHERE id = ?');
        $stmt->execute([$now->format(self::FORMAT), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `user` WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function ausZeile(array $row): Benutzer
    {
        return new Benutzer(
            id: (int) $row['id'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            passwordHash: (string) $row['password_hash'],
            // An unknown value (a newer release wrote it) locks the account
            // rather than guessing what it may do.
            status: BenutzerStatus::tryFrom((string) $row['status']) ?? BenutzerStatus::Gesperrt,
            rolle: Rolle::tryFrom((string) $row['rolle']) ?? Rolle::Vorstand,
            barrierefrei: (bool) $row['modus_barrierefrei'],
            // An unknown method (a newer release wrote it) becomes the mail
            // code, never "not set up": that would open the account on the
            // password alone and let it set up a new factor unasked.
            mfaMethod: $row['mfa_method'] === null ? null : MfaMethode::tryFrom((string) $row['mfa_method']) ?? MfaMethode::Email,
            sessionEpoch: (int) $row['session_epoch'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            lastLoginAt: $row['last_login_at'] === null ? null : new \DateTimeImmutable((string) $row['last_login_at']),
        );
    }
}
