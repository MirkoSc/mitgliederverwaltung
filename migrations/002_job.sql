-- Step chains (docs/spec/01-plattform.md section 4): a long operation -
-- import, export, backup - is a row here, and the browser drives it one
-- short POST at a time. Every step is idempotent and may be repeated after
-- an interruption; the lock keeps two tabs from running the same job at once
-- and expires on its own, so a crashed step does not park the job forever.
--
-- `state` holds ids, offsets and counters - never member data, it is the
-- resumable position of the chain, not its payload. `last_error` holds the
-- exception class only, because database messages quote row data.
--
-- Status as VARCHAR and not ENUM: the authority is App\Domain\JobStatus,
-- and a new value must not cost an ALTER TABLE.
CREATE TABLE job (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    typ VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'offen',
    -- Who started it; no foreign key, the job outlives a deleted account.
    user_id BIGINT UNSIGNED NULL,
    state JSON NULL,
    -- 0 to 100.
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    locked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_job_typ_status (typ, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
