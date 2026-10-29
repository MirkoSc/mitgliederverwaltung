<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The tables of the second factor (migrations/025_zweiter_faktor.sql):
 * `mfa_totp`, `mfa_email_code`, `mfa_backup_code`, `trusted_device`. Taken
 * over from Vereinsbelege's four repositories of the same tables, folded
 * into one - they are small, belong together and have one user
 * (App\Service\Account\ZweiterFaktor).
 *
 * Every hash comes in ready-made, the secret as ciphertext: this class holds
 * no key. The "spend" methods answer true only for the one request whose
 * statement actually changed the row, so two parallel logins with the same
 * code cannot both pass.
 */
final readonly class ZweiterFaktorRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    // ------------------------------------------------------ authenticator

    /**
     * Stores a confirmed secret, replacing a previous one, with the step of
     * the confirming code already spent.
     */
    public function speichereApp(int $userId, string $secretEnc, int $step, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mfa_totp (user_id, secret_enc, last_step, created_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), last_step = VALUES(last_step),
                 created_at = VALUES(created_at)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $secretEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $step, \PDO::PARAM_INT);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /** The secret as ciphertext, or null. */
    public function appGeheimnis(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT secret_enc FROM mfa_totp WHERE user_id = ?');
        $stmt->execute([$userId]);
        $wert = $stmt->fetchColumn();

        return is_string($wert) ? $wert : null;
    }

    /**
     * Records $step as the last accepted time step - true only if it is
     * newer than the one so far, in one statement.
     */
    public function verbraucheSchritt(int $userId, int $step): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mfa_totp SET last_step = ? WHERE user_id = ? AND (last_step IS NULL OR last_step < ?)',
        );
        $stmt->bindValue(1, $step, \PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $step, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function loescheApp(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM mfa_totp WHERE user_id = ?')->execute([$userId]);
    }

    // --------------------------------------------------------- e-mail code

    /** A fresh code replaces the open one, with its attempts back at zero. */
    public function speichereMailCode(int $userId, string $hash, \DateTimeImmutable $ablauf, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mfa_email_code (user_id, code_hash, expires_at, attempts, created_at) VALUES (?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
                 attempts = 0, created_at = VALUES(created_at)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $hash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $ablauf->format(self::FORMAT));
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /**
     * Counts one attempt at the open code, if it is still usable, and
     * hands back its hash - in one step, so parallel guesses cannot get past
     * the limit either.
     *
     * @return string|null the hash to compare against, or null when there
     *                     is no usable code (none, expired, attempts used up)
     */
    public function versuchMailCode(int $userId, int $hoechstens, \DateTimeImmutable $now): ?string
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mfa_email_code SET attempts = attempts + 1 WHERE user_id = ? AND attempts < ? AND expires_at > ?',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $hoechstens, \PDO::PARAM_INT);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->execute();
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $lesen = $this->pdo->prepare('SELECT code_hash FROM mfa_email_code WHERE user_id = ?');
        $lesen->execute([$userId]);
        $hash = $lesen->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    /** Removes exactly this code - true only for the request that did. */
    public function verbraucheMailCode(int $userId, string $hash): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM mfa_email_code WHERE user_id = ? AND code_hash = ?');
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $hash, \PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function loescheMailCode(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM mfa_email_code WHERE user_id = ?')->execute([$userId]);
    }

    // -------------------------------------------------------- backup codes

    /**
     * Replaces the whole set: the previous ten, spent or not, stop working
     * the moment the new ten exist.
     *
     * @param list<string> $hashes
     */
    public function ersetzeBackupCodes(int $userId, array $hashes, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM mfa_backup_code WHERE user_id = ?')->execute([$userId]);
            $insert = $this->pdo->prepare('INSERT INTO mfa_backup_code (user_id, code_hash, used_at, created_at) VALUES (?, ?, NULL, ?)');
            foreach ($hashes as $hash) {
                $insert->bindValue(1, $userId, \PDO::PARAM_INT);
                $insert->bindValue(2, $hash, \PDO::PARAM_LOB);
                $insert->bindValue(3, $now->format(self::FORMAT));
                $insert->execute();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Marks the code used - true only for the request that did. */
    public function verbraucheBackupCode(int $userId, string $hash, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_backup_code SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $stmt->bindValue(1, $now->format(self::FORMAT));
        $stmt->bindValue(2, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $hash, \PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function offeneBackupCodes(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mfa_backup_code WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------- remembered devices

    public function merkeGeraet(int $userId, string $hash, \DateTimeImmutable $ablauf, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO trusted_device (user_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $hash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->bindValue(4, $ablauf->format(self::FORMAT));
        $stmt->execute();
    }

    public function geraetGemerkt(int $userId, string $hash, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trusted_device WHERE user_id = ? AND token_hash = ? AND expires_at > ?');
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $hash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function gemerkteGeraete(int $userId, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trusted_device WHERE user_id = ? AND expires_at > ?');
        $stmt->execute([$userId, $now->format(self::FORMAT)]);

        return (int) $stmt->fetchColumn();
    }

    public function vergesseGeraete(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM trusted_device WHERE user_id = ?')->execute([$userId]);
    }

    /** Housekeeping for the cron: an expired token lets nobody in. */
    public function loescheAbgelaufeneGeraete(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM trusted_device WHERE expires_at <= ?');
        $stmt->execute([$now->format(self::FORMAT)]);

        return $stmt->rowCount();
    }

    /** Housekeeping for the cron: an expired code opens nothing. */
    public function loescheAbgelaufeneMailCodes(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM mfa_email_code WHERE expires_at <= ?');
        $stmt->execute([$now->format(self::FORMAT)]);

        return $stmt->rowCount();
    }
}
