-- Key/value settings (docs/spec/03-datenhaltung.md section 2). The value is
-- JSON, so a setting can be a number, a flag or a list (e.g. the round
-- birthdays) without a column per type; App\Repository\SettingRepository
-- encodes and decodes it.
--
-- Nothing secret in here: settings are plaintext and travel in every backup.
-- An encrypted value (the SMTP password) goes into a `*_enc` setting as
-- server-key ciphertext.
--
-- The column is called `name`, not `key`: KEY is a reserved word in
-- MySQL/MariaDB and would have to be backquoted in every statement.
CREATE TABLE setting (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value JSON NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
