-- Encrypted file storage (docs/spec/02-sicherheit.md section 2,
-- docs/spec/03-datenhaltung.md section 3): proof scans and archived direct
-- debit files never touch the disk or the database in the clear. The content
-- is a secretstream_xchacha20poly1305 stream under a random key of its own,
-- and that key is stored here only as secretbox ciphertext under the server
-- key from shared/config.php (never in the database, never in a dump).
--
-- The table is called `file_blob` and not `blob` because BLOB is a reserved
-- word in MySQL/MariaDB.
--
-- `mime` and `original_name` are plaintext columns: there is no vault any
-- more (E-11), the member data around them is plaintext too, and the pages
-- that list proofs need the name without decrypting anything. The name of
-- the stored file, on the other hand, is a random id (`fs_name`) and never
-- derived from the upload.
CREATE TABLE file_blob (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- `db` | `fs` as VARCHAR and not ENUM: the authority is the PHP enum
    -- App\Domain\BlobStorage, and a new backend must not cost an ALTER TABLE.
    storage VARCHAR(8) NOT NULL,
    -- Storage backend `fs` only: the random name below shared/var/blobs/.
    fs_name VARCHAR(64) NULL,
    -- Length of the plaintext in bytes.
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- SHA-256 over the ciphertext, for the integrity check. NULL means the
    -- stream was never finished: a write that broke off halfway leaves a
    -- draft row, not a readable blob.
    cipher_sha256 BINARY(32) NULL,
    -- ServerCrypto::encrypt(file key) = version (1) | nonce (24) | key (32) |
    -- tag (16) = 73 bytes.
    key_enc VARBINARY(96) NOT NULL,
    -- Version (1 byte) | secretstream header (24 bytes).
    header VARBINARY(32) NOT NULL,
    mime VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_file_blob_fs_name (fs_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Storage backend `db`: the ciphertext stream cut into rows. MEDIUMBLOB and
-- chunks well below 1 MiB keep both max_allowed_packet and the memory of a
-- single request small - a chunk is read and handed on one at a time, the
-- whole file is never in memory.
CREATE TABLE file_blob_chunk (
    blob_id BIGINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    data MEDIUMBLOB NOT NULL,
    PRIMARY KEY (blob_id, seq),
    CONSTRAINT fk_file_blob_chunk_blob FOREIGN KEY (blob_id)
        REFERENCES file_blob (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
