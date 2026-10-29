-- Second factor (docs/spec/02-sicherheit.md section 3, issue #8): the
-- authenticator secret, the open e-mail code, the ten backup codes and the
-- remembered devices. Taken over from Vereinsbelege (migrations 008 and 045
-- there), without the blind index: every `*_hash` here is an HMAC-SHA256
-- under the server key (App\Service\Account\ZweiterFaktor::hash()), salted
-- with the user id - so a database reader holds neither a code nor
-- something a six-digit code could be guessed back from.
--
-- `user.mfa_method` (migration 015) is the one answer to "has this account
-- set up a second factor": NULL = not yet, the login guard then forces the
-- setup.

-- One authenticator secret per user, server-key ciphertext (it has to be
-- readable: a TOTP code cannot be checked against a hash of the secret).
-- Written only once its first code is confirmed - an abandoned setup never
-- touches it (App\Service\Account\ZweiterFaktorEinrichtung).
CREATE TABLE mfa_totp (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    -- version(1) | nonce(24) | secretbox(20-byte secret + MAC 16) = 61 bytes.
    secret_enc VARBINARY(96) NOT NULL,
    -- The time step of the last code accepted: each step works once
    -- (02 section 3, "jeder Zeitschritt nur einmal").
    last_step BIGINT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_mfa_totp_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The open e-mail code, at most one per user: a fresh code overwrites the
-- row ("6-stellige Nummer, 10 min gültig, max. 5 Versuche").
CREATE TABLE mfa_email_code (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    code_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_mfa_email_code_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ten one-time codes per user, replaced together. `used_at` marks a spent
-- code instead of deleting it, so "7 von 10 übrig" needs no counter.
CREATE TABLE mfa_backup_code (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash BINARY(32) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_mfa_backup_code (user_id, code_hash),
    CONSTRAINT fk_mfa_backup_code_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Dieses Gerät 30 Tage merken": one row per remembered browser; the token
-- itself lives only in that browser's cookie.
CREATE TABLE trusted_device (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_trusted_device_token (token_hash),
    KEY idx_trusted_device_user (user_id),
    -- The cron sweep (App\Service\Cron\ZweiterFaktorCleanupTask).
    KEY idx_trusted_device_expires (expires_at),
    CONSTRAINT fk_trusted_device_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
