<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;
use App\Repository\AuthTokenRepository;
use App\Repository\BenutzerRepository;
use App\Service\RateLimiter;

/**
 * Changing the own password from the user menu (docs/spec/06-oberflaeche.md
 * section 2). The current password is asked for again: an unattended,
 * logged-in browser must not be enough to take the account over.
 *
 * The change ends every OTHER session of the account (`session_epoch`) and
 * voids an open reset link; the controller lets the current session adopt
 * the new value.
 */
final readonly class PasswortAenderung
{
    private const string LIMIT = 'passwort.konto';

    /** Wrong current passwords per account and window, like the login. */
    public const int JE_KONTO = RateLimiter::LOGIN_JE_KONTO;

    private BenutzerRepository $benutzer;

    public function __construct(
        private \PDO $pdo,
        private RateLimiter $limits,
        private PasswordPolicy $policy,
        private PasswordHasher $passwoerter = new PasswordHasher(),
    ) {
        $this->benutzer = new BenutzerRepository($pdo);
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->pdo(),
            RateLimiter::aus($d),
            PasswordPolicy::aus($d),
        ));
    }

    public function aendern(
        int $userId,
        #[\SensitiveParameter] string $aktuell,
        #[\SensitiveParameter] string $neu,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): PasswortErgebnis {
        $benutzer = $this->benutzer->findById($userId);
        if ($benutzer === null || !$benutzer->darfAnmelden()) {
            return PasswortErgebnis::fehler('Ihr Konto ist nicht mehr aktiv.');
        }

        if (!$this->limits->versuch(self::LIMIT, (string) $userId, self::JE_KONTO, $now)) {
            return PasswortErgebnis::fehler('Zu viele Versuche. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.');
        }
        if (!$this->passwoerter->verify($aktuell, $benutzer->passwordHash)) {
            return PasswortErgebnis::fehler('Das aktuelle Passwort stimmt nicht.');
        }
        $this->limits->reset(self::LIMIT, (string) $userId);

        $fehler = $this->policy->pruefeNeu($neu, $wiederholung);
        if ($fehler !== []) {
            return PasswortErgebnis::fehler(...$fehler);
        }
        if ($this->passwoerter->verify($neu, $benutzer->passwordHash)) {
            return PasswortErgebnis::fehler('Das neue Passwort ist dasselbe wie das bisherige. Bitte wählen Sie ein anderes.');
        }

        $hash = $this->passwoerter->hash($neu);
        $this->pdo->beginTransaction();
        try {
            $this->benutzer->updatePasswordHash($userId, $hash);
            $epoch = $this->benutzer->bumpSessionEpoch($userId);
            // A reset link asked for before the change must not undo it.
            new AuthTokenRepository($this->pdo)->deleteForUser($userId, PasswortReset::TYP);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return PasswortErgebnis::erfolg($userId, $epoch);
    }
}
