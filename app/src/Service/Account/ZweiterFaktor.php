<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;
use App\Domain\Benutzer;
use App\Domain\MfaMethode;
use App\Repository\BenutzerRepository;
use App\Repository\ZweiterFaktorRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;

/**
 * The second factor (docs/spec/02-sicherheit.md section 3): checking a code
 * from the app, the mail or the backup list, issuing mail codes, setting a
 * method up, the backup codes and the remembered devices. Taken over from
 * Vereinsbelege's MfaService, MfaEnrollment and Neuanmeldung, folded into
 * one class and without the blind index and the vault.
 *
 * No HTTP, no session, no mail: the controllers (App\PublicPages\
 * ZweiterFaktorAnmeldung, App\App\ZweiterFaktorController) hold the
 * in-between state and send the mails.
 *
 *   - **Only hashes are stored**: codes and device tokens as HMAC-SHA256
 *     under the server key, salted with the user id (hash()). Only the app
 *     secret is ciphertext - a TOTP code cannot be checked against a hash.
 *   - **Single use**: an app code's time step, a mail code and a backup
 *     code are each spent by the one request whose statement changed the
 *     row (App\Repository\ZweiterFaktorRepository).
 *   - **Limits**: every attempt counts against the IP and the account
 *     BEFORE it is checked (App\Service\RateLimiter, like the password);
 *     a mail code additionally dies after five wrong tries, and mail codes
 *     can only be requested a few times per window.
 */
final readonly class ZweiterFaktor
{
    public const string LIMIT_IP = 'mfa.ip';

    public const string LIMIT_KONTO = 'mfa.konto';

    public const string LIMIT_MAIL = 'mfa.mail';

    public const string LIMIT_NEU = 'mfa.neu';

    /**
     * Tighter than the password (20/10): a second factor exists to make
     * guessing impractical, and a real person rarely needs more than a
     * couple of tries.
     */
    public const int JE_IP = 20;

    public const int JE_KONTO = 8;

    /** Mail codes per account and window - every one is a mail. */
    public const int MAILS_JE_KONTO = 5;

    public const int CODE_STELLEN = 6;

    public const int CODE_GUELTIG_SEKUNDEN = 600;

    /** "max. 5 Versuche" per mail code. */
    public const int CODE_VERSUCHE = 5;

    /** "Dieses Gerät 30 Tage merken". */
    public const int GERAET_TAGE = 30;

    /** ±1 time step (±30 s) against clock drift between server and phone. */
    public const int APP_TOLERANZ = 1;

    public function __construct(
        private ZweiterFaktorRepository $faktoren,
        private BenutzerRepository $benutzer,
        private ServerCrypto $crypto,
        private RateLimiter $limits,
        private PasswordHasher $passwoerter = new PasswordHasher(),
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            new ZweiterFaktorRepository($d->pdo()),
            new BenutzerRepository($d->pdo()),
            ServerCrypto::aus($d),
            RateLimiter::aus($d),
        ));
    }

    // ------------------------------------------------------------- limits

    /**
     * Counts one attempt at a code and says whether it may go ahead. Both
     * counters, and both always: whoever is stopped by one must not learn
     * anything about the other.
     */
    public function versuch(string $ip, int $userId, ?\DateTimeImmutable $now = null): bool
    {
        $ipFrei = $this->limits->versuch(self::LIMIT_IP, $ip, self::JE_IP, $now);
        $kontoFrei = $this->limits->versuch(self::LIMIT_KONTO, (string) $userId, self::JE_KONTO, $now);

        return $ipFrei && $kontoFrei;
    }

    /**
     * After a correct code: only the account's counter, like the password
     * (App\Service\Account\Anmeldung) - clearing the IP's would let one
     * working account wipe the limit of its address.
     */
    public function erfolg(int $userId): void
    {
        $this->limits->reset(self::LIMIT_KONTO, (string) $userId);
    }

    // ------------------------------------------------------------- checks

    /**
     * Checks a code at the login or the re-confirmation: one from the app,
     * from the last mail, or a backup code. The caller counted the attempt
     * (versuch()) before.
     *
     * Six digits are an app or mail code, anything else is tried as a
     * backup code. Both kinds of six digits are accepted whatever the
     * method, which is safe because switching methods removes the other
     * one's secret (richteAppEin(), richteMailEin()).
     */
    public function pruefe(int $userId, #[\SensitiveParameter] string $code, ?\DateTimeImmutable $now = null): ?Nachweis
    {
        $now ??= new \DateTimeImmutable();
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if (preg_match('/^\d{6}$/D', $code) === 1) {
            return match (true) {
                $this->pruefeApp($userId, $code, $now) => Nachweis::App,
                $this->pruefeMailCode($userId, $code, $now) => Nachweis::Email,
                default => null,
            };
        }

        $hash = $this->hash('backup', $userId, BackupCodes::normalize($code));

        return $this->faktoren->verbraucheBackupCode($userId, $hash, $now) ? Nachweis::BackupCode : null;
    }

    /** Each time step once: a code seen over a shoulder works no second time. */
    public function pruefeApp(int $userId, #[\SensitiveParameter] string $code, ?\DateTimeImmutable $now = null): bool
    {
        $geheimnis = $this->faktoren->appGeheimnis($userId);
        if ($geheimnis === null) {
            return false;
        }

        $schritt = Totp::matchingStep($this->crypto->decrypt($geheimnis), $code, $now, self::APP_TOLERANZ);

        return $schritt !== null && $this->faktoren->verbraucheSchritt($userId, $schritt);
    }

    /**
     * The open mail code: ten minutes, five tries, then it is gone - every
     * try counts, the right one included.
     */
    public function pruefeMailCode(int $userId, #[\SensitiveParameter] string $code, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $gespeichert = $this->faktoren->versuchMailCode($userId, self::CODE_VERSUCHE, $now);
        if ($gespeichert === null || !hash_equals($gespeichert, $this->hash('mail', $userId, trim($code)))) {
            return false;
        }

        return $this->faktoren->verbraucheMailCode($userId, $gespeichert);
    }

    // ---------------------------------------------------------- mail code

    /**
     * A fresh mail code, replacing the open one.
     *
     * @return string|null the code for the mail; null when the account
     *                     asked for too many in this window
     */
    public function neuerMailCode(int $userId, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable();
        if (!$this->limits->versuch(self::LIMIT_MAIL, (string) $userId, self::MAILS_JE_KONTO, $now)) {
            return null;
        }

        $code = str_pad((string) random_int(0, 10 ** self::CODE_STELLEN - 1), self::CODE_STELLEN, '0', STR_PAD_LEFT);
        $this->faktoren->speichereMailCode(
            $userId,
            $this->hash('mail', $userId, $code),
            $now->modify(sprintf('+%d seconds', self::CODE_GUELTIG_SEKUNDEN)),
            $now,
        );

        return $code;
    }

    // ------------------------------------------------------------- setup

    /** A fresh secret for the app; stored only once its first code is confirmed. */
    public function neuesAppGeheimnis(): string
    {
        return Totp::generateSecret();
    }

    /**
     * Confirms an app secret with its first code and, only then, makes it
     * the account's method - the step of that code already spent. A mail
     * code still open goes, it is no way in any more.
     *
     * @return list<string>|null the ten new backup codes; null when the
     *                           code does not fit the secret
     */
    public function richteAppEin(int $userId, #[\SensitiveParameter] string $geheimnis, #[\SensitiveParameter] string $code, ?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable();
        $schritt = Totp::matchingStep($geheimnis, $code, $now, self::APP_TOLERANZ);
        if ($geheimnis === '' || $schritt === null) {
            return null;
        }

        $this->faktoren->speichereApp($userId, $this->crypto->encrypt($geheimnis), $schritt, $now);
        $this->faktoren->loescheMailCode($userId);
        $this->benutzer->updateMfaMethode($userId, MfaMethode::App);

        return $this->neueBackupCodes($userId, $now);
    }

    /**
     * Makes the mail the account's method. The caller proved the mailbox
     * works (pruefeMailCode()). An app secret from before goes: the login
     * takes app codes whatever the method, and an app somebody switched
     * away from - perhaps because the phone is lost - must not keep
     * opening the account.
     *
     * @return list<string> the ten new backup codes
     */
    public function richteMailEin(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $this->faktoren->loescheApp($userId);
        $this->benutzer->updateMfaMethode($userId, MfaMethode::Email);

        return $this->neueBackupCodes($userId, $now);
    }

    /**
     * Replaces all ten backup codes.
     *
     * @return list<string> the codes, shown once
     */
    public function neueBackupCodes(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $codes = BackupCodes::generate();
        $this->faktoren->ersetzeBackupCodes(
            $userId,
            array_map(fn(string $code): string => $this->hash('backup', $userId, BackupCodes::normalize($code)), $codes),
            $now ?? new \DateTimeImmutable(),
        );

        return $codes;
    }

    public function offeneBackupCodes(int $userId): int
    {
        return $this->faktoren->offeneBackupCodes($userId);
    }

    // ----------------------------------------------------- re-confirmation

    /**
     * Before the second factor changes: the password AND the current
     * factor (02 section 3, "Ändern nur nach erneuter Bestätigung") - an
     * unlocked computer must not be enough to move the factor to somebody
     * else's phone. The code is only tried once the password is right, so a
     * mistyped password spends no code.
     *
     * @return string|null what was wrong, or null when confirmed
     */
    public function bestaetige(
        Benutzer $benutzer,
        #[\SensitiveParameter] string $passwort,
        #[\SensitiveParameter] string $code,
        string $ip,
        ?\DateTimeImmutable $now = null,
    ): ?string {
        $now ??= new \DateTimeImmutable();
        $zuViele = 'Zu viele Versuche. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.';

        if (!$this->limits->versuch(self::LIMIT_NEU, (string) $benutzer->id, RateLimiter::LOGIN_JE_KONTO, $now)) {
            return $zuViele;
        }
        if (!$this->passwoerter->verify($passwort, $benutzer->passwordHash)) {
            return 'Das Passwort stimmt nicht.';
        }
        $this->limits->reset(self::LIMIT_NEU, (string) $benutzer->id);

        if (!$this->versuch($ip, $benutzer->id, $now)) {
            return $zuViele;
        }
        if ($this->pruefe($benutzer->id, $code, $now) === null) {
            return 'Der Code ist ungültig oder abgelaufen.';
        }
        $this->erfolg($benutzer->id);

        return null;
    }

    // -------------------------------------------------- remembered devices

    /**
     * @return string the token for the cookie (App\PublicPages\
     *                ZweiterFaktorAnmeldung::GERAET_COOKIE)
     */
    public function merkeGeraet(int $userId, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $token = bin2hex(random_bytes(32));
        $this->faktoren->merkeGeraet(
            $userId,
            $this->hash('geraet', $userId, $token),
            $now->modify(sprintf('+%d days', self::GERAET_TAGE)),
            $now,
        );

        return $token;
    }

    public function geraetGemerkt(int $userId, #[\SensitiveParameter] ?string $token, ?\DateTimeImmutable $now = null): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return $this->faktoren->geraetGemerkt($userId, $this->hash('geraet', $userId, $token), $now ?? new \DateTimeImmutable());
    }

    public function gemerkteGeraete(int $userId, ?\DateTimeImmutable $now = null): int
    {
        return $this->faktoren->gemerkteGeraete($userId, $now ?? new \DateTimeImmutable());
    }

    public function vergesseGeraete(int $userId): void
    {
        $this->faktoren->vergesseGeraete($userId);
    }

    /**
     * HMAC under the server key - a database reader cannot test six digits
     * against it - namespaced by purpose and salted with the user id, so the
     * same code of two accounts never hashes the same.
     */
    private function hash(string $zweck, int $userId, #[\SensitiveParameter] string $wert): string
    {
        return $this->crypto->hmac('mfa.' . $zweck . "\0" . $userId . ':' . $wert);
    }
}
