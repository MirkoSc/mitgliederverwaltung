<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Mail\MailSettingsRepository;

/**
 * What the key change (App\Service\Crypto\Schluesselwechsel) reads and
 * writes: every value under the server key that lives in the database -
 * the file keys of the blobs, the authenticator secrets and the SMTP
 * password - and the second-factor hashes that a new key turns invalid.
 *
 * Every write is a compare-and-swap on the old value: a row somebody else
 * changed or deleted meanwhile is left alone, never overwritten with what
 * the chain read before.
 */
final readonly class SchluesselwechselRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function anzahlDateien(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM file_blob')?->fetchColumn();
    }

    public function anzahlAppGeheimnisse(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM mfa_totp')?->fetchColumn();
    }

    /**
     * `file_blob.key_enc` after the id $nach, in id order.
     *
     * @return array<int, string> id => key_enc
     */
    public function dateischluessel(int $nach, int $anzahl): array
    {
        return $this->liste('SELECT id, key_enc FROM file_blob WHERE id > ? ORDER BY id LIMIT ?', $nach, $anzahl);
    }

    public function dateischluesselErsetzen(int $id, string $alt, string $neu): bool
    {
        return $this->ersetzen('UPDATE file_blob SET key_enc = ? WHERE id = ? AND key_enc = ?', $id, $alt, $neu);
    }

    /**
     * `mfa_totp.secret_enc` after the user id $nach, in id order.
     *
     * @return array<int, string> user id => secret_enc
     */
    public function appGeheimnisse(int $nach, int $anzahl): array
    {
        return $this->liste('SELECT user_id, secret_enc FROM mfa_totp WHERE user_id > ? ORDER BY user_id LIMIT ?', $nach, $anzahl);
    }

    public function appGeheimnisErsetzen(int $userId, string $alt, string $neu): bool
    {
        return $this->ersetzen('UPDATE mfa_totp SET secret_enc = ? WHERE user_id = ? AND secret_enc = ?', $userId, $alt, $neu);
    }

    /**
     * The stored SMTP password (base64 ciphertext), null when none is set.
     */
    public function smtpPasswort(): ?string
    {
        $wert = new SettingRepository($this->pdo)->text(MailSettingsRepository::PASSWORT);

        return $wert === '' ? null : $wert;
    }

    public function smtpPasswortErsetzen(string $alt, string $neu): bool
    {
        return new SettingRepository($this->pdo)->ersetzen(MailSettingsRepository::PASSWORT, $alt, $neu);
    }

    /**
     * Removes the second-factor values that are HMACs under the old key and
     * so cannot match anything any more: backup codes, remembered devices,
     * open mail codes - only those created up to $vor, so whatever a user
     * set up under the new key after it stays.
     *
     * @return array{backup_codes: int, geraete: int, mail_codes: int}
     */
    public function zweiterFaktorHashesLoeschen(\DateTimeImmutable $vor): array
    {
        $zeit = $vor->format(self::FORMAT);
        $anzahl = [];
        foreach (['backup_codes' => 'mfa_backup_code', 'geraete' => 'trusted_device', 'mail_codes' => 'mfa_email_code'] as $schluessel => $tabelle) {
            // <=: a row of the very second may be either key's - removed
            // rather than listed and never working
            $stmt = $this->pdo->prepare('DELETE FROM ' . $tabelle . ' WHERE created_at <= ?');
            $stmt->execute([$zeit]);
            $anzahl[$schluessel] = $stmt->rowCount();
        }

        return $anzahl;
    }

    /**
     * @return array<int, string>
     */
    private function liste(string $sql, int $nach, int $anzahl): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $nach, \PDO::PARAM_INT);
        $stmt->bindValue(2, $anzahl, \PDO::PARAM_INT);
        $stmt->execute();
        $werte = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$id, $wert]) {
            $werte[(int) $id] = (string) $wert;
        }

        return $werte;
    }

    private function ersetzen(string $sql, int $id, string $alt, string $neu): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $neu, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->bindValue(3, $alt, \PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }
}
