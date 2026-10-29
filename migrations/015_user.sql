-- Users of the application (docs/spec/02-sicherheit.md sections 3-6,
-- docs/spec/03-datenhaltung.md section 2). Taken over from Vereinsbelege
-- without the vault: no key pair, no grant, and the address is a plain
-- column (E-11) - the login looks it up with an ordinary index.
--
-- A user is not a member (02 section 5): nothing here is member data.
CREATE TABLE `user` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Trimmed and lowercased (App\Domain\Benutzer::normalisiereEmail()), so
    -- the UNIQUE key also catches "Erika@…" next to "erika@…". 255 bytes
    -- cover the longest valid address (RFC 5321).
    email VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    -- password_hash() with PASSWORD_ARGON2ID (fallback bcrypt where PHP lacks
    -- libargon2). Empty while the invitation is not answered: an empty hash
    -- verifies against nothing (App\Service\Account\PasswordHasher).
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    -- `eingeladen` | `aktiv` | `gesperrt`, `admin` | `vorstand`: VARCHAR and
    -- not ENUM, the authority is the PHP enum (App\Domain\BenutzerStatus,
    -- App\Domain\Rolle) - the pattern of `job.status`/`mail_queue.status`.
    status VARCHAR(16) NOT NULL DEFAULT 'eingeladen',
    rolle VARCHAR(16) NOT NULL DEFAULT 'vorstand',
    -- Barrierefrei-Modus (docs/spec/06-oberflaeche.md section 4); also
    -- doubles the idle timeout (App\Http\LoginGuard).
    modus_barrierefrei TINYINT(1) NOT NULL DEFAULT 0,
    -- The second factor of the account (issue #8); NULL = not set up yet.
    mfa_method VARCHAR(16) NULL,
    -- Ending sessions without a session registry: every session copies this
    -- counter at login (App\Http\Session::login()) and App\Http\LoginGuard
    -- ends any session whose copy no longer matches. A reset, a lock and a
    -- password change raise it.
    session_epoch INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
