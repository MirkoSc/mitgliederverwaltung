-- Audit log (docs/spec/02-sicherheit.md section 8): append-only, every row
-- chained to the one before by `prev_hash` / `hash` (SHA-256), so a changed
-- or removed row shows up when the chain is checked.
--
-- `id` is the order of the chain, hence AUTO_INCREMENT without gaps being
-- relied on. `user_id` has no foreign key: the entry outlives the account.
-- `ip_hash` is an HMAC under the server key, never the address itself.
-- `details` holds ids and counters only - no member data (CLAUDE.md section 4).
CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ts DATETIME NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity VARCHAR(64) NULL,
    entity_id VARCHAR(64) NULL,
    ip_hash CHAR(64) NULL,
    details JSON NOT NULL,
    prev_hash CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    KEY idx_audit_log_action (action),
    KEY idx_audit_log_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
