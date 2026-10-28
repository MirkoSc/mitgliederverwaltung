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
     */
    public const array OPTIONEN = [
        'cache_limiter' => 'nocache',
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

        unset($_SESSION['csrf']);
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
