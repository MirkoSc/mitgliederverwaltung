<?php

declare(strict_types=1);

namespace App\Http;

use App\View\Flash;
use App\View\FlashArt;

/**
 * PHP session wrapper (docs/spec/02-sicherheit.md section 4). Taken over
 * from Vereinsbelege, without the vault.
 *
 * It carries the CSRF token, flash messages and who is logged in: the user
 * id, the account's `session_epoch` at login and the two timestamps the
 * timeouts are measured against. Nothing else about the account and never
 * member data - the session store is a file on the webspace.
 *
 * The cookie flags are set here once, because they have to hold for every
 * later use.
 */
final class Session
{
    /**
     * Runtime options for session_start(), set here instead of trusting the
     * host's php.ini: cache_limiter "nocache" gives every page with a session
     * `Cache-Control: no-store, no-cache, must-revalidate`, so a shared
     * computer's browser cache keeps no member data (02 section 7).
     *
     * Deliberately NOT use_strict_mode: the id is regenerated at login, and a
     * background request of another tab still in flight with the old id
     * would then be handed a fresh, empty session - which, if it lands last,
     * logs the user out of every tab. Fixation is closed by the regeneration
     * at login itself (login()).
     *
     * gc_maxlifetime: PHP's default of 24 minutes would let the garbage
     * collector delete a session file before the idle limit of 30 minutes
     * (60 in the Barrierefrei-Modus, App\Http\LoginGuard) is reached - the
     * user would be logged out early, at random.
     */
    public const array OPTIONEN = [
        'cache_limiter' => 'nocache',
        'gc_maxlifetime' => LoginGuard::LEERLAUF_BARRIEREFREI_SEKUNDEN + 15 * 60,
    ];

    /**
     * The session cookie's name over HTTPS. The `__Host-` prefix makes the
     * browser refuse the cookie unless it is Secure, has Path=/ and no
     * Domain - so neither a sibling subdomain nor a plain-HTTP response can
     * plant or overwrite it.
     */
    public const string NAME_HTTPS = '__Host-sid';

    /** Over plain HTTP (only the docker dev setup) PHP's default name stays. */
    public const string NAME_HTTP = 'PHPSESSID';

    public function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $https = Request::httpsFromGlobals();
        session_name($https ? self::NAME_HTTPS : self::NAME_HTTP);
        session_set_cookie_params([
            'httponly' => true,
            // Derived from the request instead of hardcoded: the docker dev
            // setup serves http://localhost:8080, where a secure cookie
            // would silently never be stored. Production runs on HTTPS
            // (installation requirement), so there it is always true.
            'secure' => $https,
            'samesite' => 'Lax',
            'path' => '/',
            // Pinned empty: `__Host-` forbids a Domain attribute.
            'domain' => '',
        ]);
        session_start(self::OPTIONEN);
    }

    /**
     * Writes the session and releases its lock - before a response that
     * takes long to send (a download), so the user's other tabs need not
     * wait for it. Changes after this are not saved.
     */
    public function schliessen(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
            // A start() in the same request (the flash on the login page
            // after logout, reset or invitation) would otherwise read the id
            // from the request's cookie again - the old one, while the
            // browser already holds the regenerated one, and the flash went
            // nowhere (issue #36). A preset id is taken as it is and sent as
            // a cookie.
            $neu = session_create_id();
            if (is_string($neu)) {
                session_id($neu);
            }
        }
    }

    /**
     * Marks this session as logged in. Regenerating first means a session
     * id somebody planted before the login is not the id that carries the
     * account afterwards; the CSRF token is dropped with it, for the same
     * reason.
     *
     * $epoch is the account's `user.session_epoch` at login: App\Http\
     * LoginGuard ends the session once the account's value moves on
     * (password reset, lock).
     */
    public function login(int $userId, ?\DateTimeImmutable $now = null, int $epoch = 0): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Nothing of a login or a setup in progress outlives the login: a
        // re-confirmation of the second factor belongs to one login only.
        unset($_SESSION['csrf'], $_SESSION['rolle'], $_SESSION['mfa_offen'], $_SESSION['mfa_einrichtung'], $_SESSION['mfa_bestaetigt']);
        $zeit = ($now ?? new \DateTimeImmutable())->getTimestamp();
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = $zeit;
        $_SESSION['last_seen_at'] = $zeit;
        $_SESSION['session_epoch'] = $epoch;
    }

    /** The `user.session_epoch` this session logged in with. */
    public function epoch(): int
    {
        $epoch = $_SESSION['session_epoch'] ?? 0;

        return is_int($epoch) ? $epoch : -1;
    }

    /**
     * Keeps THIS session alive across a change that ends all others (the
     * own password change): it takes over the account's new epoch, with a
     * fresh id.
     */
    public function adoptEpoch(int $epoch): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['session_epoch'] = $epoch;
    }

    /**
     * Regenerates the session id when the account's role changed since the
     * last request (02 section 4: "ID-Regeneration bei Login und
     * Rechtewechsel") - App\Http\LoginGuard calls it on every request with
     * the role it just read. The first call after the login only records
     * the role; login() itself regenerated already.
     */
    public function rolleAbgleichen(string $rolle): void
    {
        $bisher = $_SESSION['rolle'] ?? null;
        if ($bisher === $rolle) {
            return;
        }
        if (is_string($bisher) && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['rolle'] = $rolle;
    }

    /**
     * The token of a one-time link (reset, invitation) while its form is
     * open: the link's GET moves it from the URL into the session and
     * redirects to the bare path, so the page and its assets never carry it
     * in the address bar, the history or a Referer. null forgets it.
     */
    public function merkeEinmalLink(string $typ, #[\SensitiveParameter] ?string $token): void
    {
        if ($token === null) {
            unset($_SESSION['einmal_link'][$typ]);

            return;
        }
        $_SESSION['einmal_link'][$typ] = $token;
    }

    public function einmalLink(string $typ): string
    {
        $token = $_SESSION['einmal_link'][$typ] ?? '';

        return is_string($token) ? $token : '';
    }

    /**
     * A login whose password was right and whose second factor is still
     * missing (docs/spec/02-sicherheit.md section 3). Nobody is logged in
     * yet; App\PublicPages\ZweiterFaktorAnmeldung finishes it with
     * login(). $epoch is the account's `session_epoch` at the password
     * check: a reset in between ends the session it leads to.
     */
    public function merkeOffeneAnmeldung(int $userId, int $epoch, ?string $weiter, ?\DateTimeImmutable $now = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['mfa_offen'] = [
            'user_id' => $userId,
            'epoch' => $epoch,
            'weiter' => $weiter,
            'seit' => ($now ?? new \DateTimeImmutable())->getTimestamp(),
        ];
    }

    /**
     * The open login, or null when there is none or it is older than
     * $gueltigSekunden.
     *
     * @return array{user_id: int, epoch: int, weiter: ?string}|null
     */
    public function offeneAnmeldung(int $gueltigSekunden, ?\DateTimeImmutable $now = null): ?array
    {
        $offen = $_SESSION['mfa_offen'] ?? null;
        if (
            !is_array($offen) || !is_int($offen['user_id'] ?? null) || !is_int($offen['epoch'] ?? null)
            || !is_int($offen['seit'] ?? null)
            || ($now ?? new \DateTimeImmutable())->getTimestamp() - $offen['seit'] >= $gueltigSekunden
        ) {
            return null;
        }

        return [
            'user_id' => $offen['user_id'],
            'epoch' => $offen['epoch'],
            'weiter' => is_string($offen['weiter'] ?? null) ? $offen['weiter'] : null,
        ];
    }

    public function vergesseOffeneAnmeldung(): void
    {
        unset($_SESSION['mfa_offen']);
    }

    /**
     * A second factor being set up, between "method chosen" and "first code
     * confirmed": the method, and for the app its new secret as server-key
     * ciphertext (App\App\ZweiterFaktorController). null forgets it.
     *
     * @param array{methode: string, geheimnis?: string}|null $einrichtung
     */
    public function merkeEinrichtung(?array $einrichtung): void
    {
        if ($einrichtung === null) {
            unset($_SESSION['mfa_einrichtung']);

            return;
        }
        $_SESSION['mfa_einrichtung'] = $einrichtung;
    }

    /** @return array{methode: string, geheimnis?: string}|null */
    public function einrichtung(): ?array
    {
        $wert = $_SESSION['mfa_einrichtung'] ?? null;
        if (!is_array($wert) || !is_string($wert['methode'] ?? null)) {
            return null;
        }
        $einrichtung = ['methode' => $wert['methode']];
        if (is_string($wert['geheimnis'] ?? null)) {
            $einrichtung['geheimnis'] = $wert['geheimnis'];
        }

        return $einrichtung;
    }

    /**
     * Password and current factor were confirmed again just now - what a
     * change of the second factor needs first (02 section 3). null forgets
     * it: one change spends it.
     */
    public function merkeBestaetigung(?\DateTimeImmutable $now): void
    {
        if ($now === null) {
            unset($_SESSION['mfa_bestaetigt']);

            return;
        }
        $_SESSION['mfa_bestaetigt'] = $now->getTimestamp();
    }

    public function bestaetigt(int $gueltigSekunden, ?\DateTimeImmutable $now = null): bool
    {
        $zeit = $_SESSION['mfa_bestaetigt'] ?? null;

        return is_int($zeit) && ($now ?? new \DateTimeImmutable())->getTimestamp() - $zeit < $gueltigSekunden;
    }

    /** The logged-in user's id, or null. */
    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /** Pushes the idle timeout out by one request. */
    public function touch(?\DateTimeImmutable $now = null): void
    {
        $_SESSION['last_seen_at'] = ($now ?? new \DateTimeImmutable())->getTimestamp();
    }

    /**
     * Whether the session has run out: idle for too long, or simply too old
     * (docs/spec/02-sicherheit.md section 4). A session without the
     * timestamps is treated as expired; it cannot be proven fresh.
     */
    public function isExpired(int $idleSeconds, int $absoluteSeconds, ?\DateTimeImmutable $now = null): bool
    {
        $loginAt = $_SESSION['login_at'] ?? null;
        $lastSeen = $_SESSION['last_seen_at'] ?? null;
        if (!is_int($loginAt) || !is_int($lastSeen)) {
            return true;
        }

        $jetzt = ($now ?? new \DateTimeImmutable())->getTimestamp();

        return $jetzt - $lastSeen >= $idleSeconds || $jetzt - $loginAt >= $absoluteSeconds;
    }

    public function csrfToken(): string
    {
        $token = $_SESSION['csrf'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf'] = $token;
        }

        return $token;
    }

    /**
     * Token from the _csrf form field or the X-CSRF-Token header (htmx).
     */
    public function checkCsrf(Request $request): bool
    {
        $token = $request->post['_csrf'] ?? $request->header('x-csrf-token') ?? '';
        $known = $_SESSION['csrf'] ?? '';

        return is_string($token) && $token !== '' && is_string($known) && $known !== ''
            && hash_equals($known, $token);
    }

    /**
     * Never member data - see App\View\Flash.
     */
    public function flash(string $message, FlashArt $art = FlashArt::Ok): void
    {
        $_SESSION['flash'] = ['text' => $message, 'art' => $art->value];
    }

    /** Whether a message waits for this request, without taking it. */
    public function hatFlash(): bool
    {
        return is_array($_SESSION['flash'] ?? null);
    }

    public function pullFlash(): ?Flash
    {
        $gespeichert = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        if (!is_array($gespeichert) || !is_string($gespeichert['text'] ?? null)) {
            return null;
        }

        $art = is_string($gespeichert['art'] ?? null)
            ? FlashArt::tryFrom($gespeichert['art'])
            : null;

        return new Flash($gespeichert['text'], $art ?? FlashArt::Ok);
    }
}
