-- One-time links sent by mail (docs/spec/02-sicherheit.md section 3,
-- docs/spec/03-datenhaltung.md section 2): `reset` (30 minutes) and `invite`
-- (72 hours). Taken over from Vereinsbelege.
--
-- Only a digest of the token is stored: SHA-256 of the 32 random bytes the
-- link carries (App\Service\Account\EinmalLink). A database reader cannot
-- turn a row back into a working link.
CREATE TABLE auth_token (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    -- VARCHAR and not ENUM: the authority is the PHP side.
    typ VARCHAR(16) NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    -- Set when the link is used; a used row is never accepted again.
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_auth_token_hash (token_hash),
    KEY idx_auth_token_user (user_id, typ),
    KEY idx_auth_token_expires (expires_at),
    CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
