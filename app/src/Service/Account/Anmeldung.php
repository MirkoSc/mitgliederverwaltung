<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;
use App\Domain\Benutzer;
use App\Repository\BenutzerRepository;
use App\Service\RateLimiter;

/**
 * Login with e-mail address and password (docs/spec/02-sicherheit.md
 * section 3). Taken over from Vereinsbelege's LoginService, without the
 * vault unlock - here a correct password is all the first step proves.
 *
 * Nothing about HTTP lives here: no session, no redirect. What comes back is
 * a value (AnmeldeErgebnis); opening the session is the controller's job
 * (App\PublicPages\AnmeldungController). The second factor (issue #8) hooks
 * in between "password correct" and "session opened" there.
 *
 *   - **No user enumeration.** Unknown address, wrong password, locked
 *     account and open invitation give the same failure with the same
 *     sentence, and the unknown-address branch burns a dummy verification
 *     so it takes the same time as a real one.
 *   - **Brute force.** Every attempt counts against the IP (an IPv6
 *     address by its /64) *and* the account BEFORE the password is
 *     checked, in one atomic step each (App\Service\RateLimiter); the
 *     account counter is kept under the account's id, whatever spelling of
 *     the address found it. A success clears the account's counter, so for
 *     an account only failures stay counted. The IP counter runs its window
 *     out.
 */
final readonly class Anmeldung
{
    public const string LIMIT_IP = 'login.ip';

    public const string LIMIT_KONTO = 'login.konto';

    public function __construct(
        private BenutzerRepository $benutzer,
        private RateLimiter $limits,
        private PasswordHasher $passwoerter = new PasswordHasher(),
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            new BenutzerRepository($d->pdo()),
            RateLimiter::aus($d),
        ));
    }

    public function versuch(
        string $email,
        #[\SensitiveParameter] string $passwort,
        string $ip,
        ?\DateTimeImmutable $now = null,
    ): AnmeldeErgebnis {
        $now ??= new \DateTimeImmutable();
        $email = Benutzer::normalisiereEmail($email);

        $benutzer = $email === '' ? null : $this->benutzer->findByEmail($email);
        $konto = RateLimiter::konto($benutzer?->id, $email);

        // Both counters first, and both always: an attacker who is stopped
        // by the IP limit must not learn anything about the account limit.
        $ipFrei = $this->limits->versuch(self::LIMIT_IP, RateLimiter::ip($ip), RateLimiter::LOGIN_JE_IP, $now);
        $kontoFrei = $this->limits->versuch(self::LIMIT_KONTO, $konto, RateLimiter::LOGIN_JE_KONTO, $now);
        if (!$ipFrei || !$kontoFrei) {
            return AnmeldeErgebnis::fehler(AnmeldeFehler::ZuVieleVersuche, $benutzer?->id);
        }

        // Both branches end in the same answer; they differ only in how the
        // time is spent, and that is the point.
        if ($benutzer === null || !$benutzer->darfAnmelden()) {
            $this->passwoerter->verifyDummy($passwort);

            return AnmeldeErgebnis::fehler(AnmeldeFehler::Zugangsdaten, $benutzer?->id);
        }
        if (!$this->passwoerter->verify($passwort, $benutzer->passwordHash)) {
            return AnmeldeErgebnis::fehler(AnmeldeFehler::Zugangsdaten, $benutzer->id);
        }

        // Only the account's counter: clearing the IP's as well would let
        // anybody with one working account wipe the limit of their address
        // between rounds of guessing at other accounts.
        $this->limits->reset(self::LIMIT_KONTO, $konto);

        if ($this->passwoerter->needsRehash($benutzer->passwordHash)) {
            // The one moment the plaintext is in hand anyway, so raised cost
            // factors reach an old account without anybody changing anything.
            $this->benutzer->updatePasswordHash($benutzer->id, $this->passwoerter->hash($passwort));
        }

        return AnmeldeErgebnis::erfolg($benutzer);
    }

    /**
     * Records the moment a login actually finished - separate from
     * versuch(), because with a second factor (issue #8) "password correct"
     * and "logged in" are no longer the same moment.
     */
    public function abgeschlossen(int $userId, ?\DateTimeImmutable $now = null): void
    {
        $this->benutzer->touchLastLogin($userId, $now ?? new \DateTimeImmutable());
    }
}
