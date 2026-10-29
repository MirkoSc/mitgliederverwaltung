<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;
use App\Domain\Benutzer;
use App\Repository\AuthTokenRepository;
use App\Repository\BenutzerRepository;
use App\Service\RateLimiter;

/**
 * "Passwort vergessen" (docs/spec/02-sicherheit.md section 3). Taken over
 * from Vereinsbelege - without the new key pair and the lost vault grants:
 * here an account loses nothing by a reset.
 *
 * The link token: 32 random bytes as hex, 30 minutes, one use, only the
 * hash in the database (App\Service\Account\EinmalLink). Completing a reset
 * ends every session of the account (`user.session_epoch`).
 *
 * Nothing about HTTP or mail here - the controller sends the link.
 */
final readonly class PasswortReset
{
    public const string TYP = 'reset';

    public const int GUELTIG_SEKUNDEN = 30 * 60;

    private const string LIMIT_IP = 'reset.ip';

    private const string LIMIT_KONTO = 'reset.konto';

    /** Requests per IP and window - generous for a club's shared connection. */
    public const int JE_IP = 10;

    /** Requests per address and window: enough for a lost mail, not for a mail bomb. */
    public const int JE_KONTO = 3;

    public function __construct(
        private \PDO $pdo,
        private RateLimiter $limits,
        private PasswordPolicy $policy,
        private PasswordHasher $passwoerter = new PasswordHasher(),
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->pdo(),
            RateLimiter::aus($d),
            PasswordPolicy::aus($d),
        ));
    }

    /**
     * Issues a link for the account behind $email, if there is one that may
     * log in at all. Every request counts against the limits - not only
     * failures, as for the login: each request can send a mail, and that is
     * what needs bounding.
     */
    public function anfordern(string $email, string $ip, ?\DateTimeImmutable $now = null): ResetAnfrage
    {
        $now ??= new \DateTimeImmutable();
        $email = Benutzer::normalisiereEmail($email);

        $ipFrei = $this->limits->versuch(self::LIMIT_IP, $ip, self::JE_IP, $now);
        $kontoFrei = $this->limits->versuch(self::LIMIT_KONTO, $email, self::JE_KONTO, $now);
        if (!$ipFrei || !$kontoFrei) {
            return ResetAnfrage::gesperrt();
        }

        $benutzer = $email === '' ? null : $this->benutzer()->findByEmail($email);
        if ($benutzer === null || !$benutzer->darfAnmelden()) {
            // A locked account gets no link: it would only receive a
            // password the login then refuses anyway. An invited one has
            // its invitation link.
            return ResetAnfrage::keinKonto();
        }

        $token = EinmalLink::neu();
        // One valid link per account: a new request replaces the old one
        // instead of opening a second door.
        $tokens = $this->tokens();
        $tokens->deleteForUser($benutzer->id, self::TYP);
        $tokens->insert($benutzer->id, self::TYP, EinmalLink::hash($token), $now->modify('+' . self::GUELTIG_SEKUNDEN . ' seconds'), $now);

        return ResetAnfrage::link($benutzer->email, $token);
    }

    /** Whether a link is still good - for showing the form. Does not spend it. */
    public function nutzbar(#[\SensitiveParameter] string $token, ?\DateTimeImmutable $now = null): bool
    {
        return $this->finde($token, $now ?? new \DateTimeImmutable()) !== null;
    }

    public function abschliessen(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $passwort,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): PasswortErgebnis {
        $now ??= new \DateTimeImmutable();
        $gefunden = $this->finde($token, $now);
        if ($gefunden === null) {
            return PasswortErgebnis::fehler(self::linkUngueltig());
        }

        // Checked before the token is spent: a password the rule rejects
        // must not cost the user their link.
        $fehler = $this->policy->pruefeNeu($passwort, $wiederholung);
        if ($fehler !== []) {
            return PasswortErgebnis::fehler(...$fehler);
        }

        [$tokenId, $benutzer] = $gefunden;
        $hash = $this->passwoerter->hash($passwort);

        $this->pdo->beginTransaction();
        try {
            if (!$this->tokens()->markUsed($tokenId, $now)) {
                // Another request with the same link got here first.
                $this->pdo->rollBack();

                return PasswortErgebnis::fehler(self::linkUngueltig());
            }
            $this->benutzer()->updatePasswordHash($benutzer->id, $hash);
            $this->tokens()->deleteForUser($benutzer->id, self::TYP);
            $epoch = $this->benutzer()->bumpSessionEpoch($benutzer->id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return PasswortErgebnis::erfolg($benutzer->id, $epoch);
    }

    public static function linkUngueltig(): string
    {
        return 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.';
    }

    /**
     * @return array{0: int, 1: Benutzer}|null
     */
    private function finde(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): ?array
    {
        if (!EinmalLink::formGueltig($token)) {
            return null;
        }
        $zeile = $this->tokens()->findUsable(self::TYP, EinmalLink::hash($token), $now);
        if ($zeile === null) {
            return null;
        }
        // Locked in the meantime: the link is worth nothing anymore.
        $benutzer = $this->benutzer()->findById($zeile['user_id']);

        return $benutzer !== null && $benutzer->darfAnmelden() ? [$zeile['id'], $benutzer] : null;
    }

    private function benutzer(): BenutzerRepository
    {
        return new BenutzerRepository($this->pdo);
    }

    private function tokens(): AuthTokenRepository
    {
        return new AuthTokenRepository($this->pdo);
    }
}
