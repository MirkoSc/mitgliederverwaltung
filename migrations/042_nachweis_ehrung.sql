-- Proofs (docs/spec/07-mitglieder.md section 5) and honours
-- (docs/spec/09-listen-und-exporte.md section 6). Neither is versioned -
-- they are a history of their own - and both go with the member.
--
-- blob_id points to file_blob (another migration range): no foreign key, the
-- service that deletes a proof or a member deletes its blob too.

CREATE TABLE nachweis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mitglied_id BIGINT UNSIGNED NOT NULL,
    -- App\Domain\NachweisArt: rente | studium | ausbildung | sonstiges.
    art VARCHAR(16) NOT NULL,
    -- NULL = no end date; valid up to and including this day.
    gueltig_bis DATE NULL,
    bemerkung VARCHAR(500) NOT NULL DEFAULT '',
    blob_id BIGINT UNSIGNED NULL,
    -- Set automatically, never changed.
    vermerkt_von BIGINT UNSIGNED NULL,
    vermerkt_am DATETIME NOT NULL,
    KEY idx_nachweis_mitglied (mitglied_id),
    CONSTRAINT fk_nachweis_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "geehrt" per member and level (years of membership); once per level.
CREATE TABLE ehrung (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mitglied_id BIGINT UNSIGNED NOT NULL,
    stufe_jahre SMALLINT UNSIGNED NOT NULL,
    geehrt_am DATE NOT NULL,
    vermerkt_von BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_ehrung_mitglied_stufe (mitglied_id, stufe_jahre),
    CONSTRAINT fk_ehrung_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
