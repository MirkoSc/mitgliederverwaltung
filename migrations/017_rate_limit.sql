-- Rate limit counters (docs/spec/02-sicherheit.md section 3,
-- docs/spec/03-datenhaltung.md section 2), taken over from Vereinsbelege.
--
-- One row per active key and fixed window, not one per attempt: a request
-- whose window has moved on overwrites the row (App\Service\RateLimiter).
-- The key is SHA-256 over "<purpose>:<value>", so neither an IP address nor
-- an e-mail address reaches this table.
CREATE TABLE rate_limit (
    key_hash BINARY(32) NOT NULL PRIMARY KEY,
    window_start DATETIME NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    -- The cron sweep runs over the window start (App\Service\Cron\
    -- AnmeldungCleanupTask).
    KEY idx_rate_limit_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
