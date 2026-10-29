-- Associations the club belongs to (docs/spec/11-vereinsdaten.md section 4):
-- BLSV, BFV, a bowling association ... Any number of rows; the club's
-- number at the association is shown e.g. above the BLSV export (09 section 4).
--
-- The other club data (address, bank, creditor ID ...) is one JSON value in
-- the `setting` table (`verein`), the logo version another (`logo_version`).
CREATE TABLE verband (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    vereinsnummer VARCHAR(60) NOT NULL DEFAULT '',
    bemerkung VARCHAR(255) NOT NULL DEFAULT '',
    sortierung INT NOT NULL DEFAULT 0,
    KEY idx_verband_sortierung (sortierung, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
