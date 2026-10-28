-- Outgoing mail queue (docs/spec/01-plattform.md section 6). Mails go to
-- users only and carry no member data (CLAUDE.md section 4), so the columns
-- are plaintext like the rest of the database (E-11, no vault).
--
-- There is no separate lock column. A claim sets status `laeuft` and pushes
-- `next_try_at` a few minutes out as a provisional lock; the real due date is
-- written once the attempt is known to have succeeded or failed. A crashed
-- send is therefore picked up again once that placeholder passes, the same
-- effect `locked_until` has on `job`.
--
-- Status as VARCHAR and not ENUM: the authority is App\Domain\MailStatus.
CREATE TABLE mail_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    to_address VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'offen',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_try_at DATETIME NOT NULL,
    -- Exception class only, never its message (CLAUDE.md section 4): an SMTP
    -- message can quote the recipient or the credentials.
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_mail_queue_faellig (status, next_try_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
