-- Annual fees and direct debits (docs/spec/08-beitraege.md section 6).
--
-- A direct debit is never deleted, not even with a member: it keeps its own
-- copy of the transactions (`daten`) and of the file that went to the bank
-- (datei_blob_id, file_blob of another migration range - no foreign key).
-- Annual fees, on the other hand, are deleted with their member.

CREATE TABLE einzug (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- App\Domain\EinzugStatus: erzeugt | bestaetigt | verworfen.
    status VARCHAR(16) NOT NULL DEFAULT 'erzeugt',
    faellig_am DATE NOT NULL,
    erstellt_von BIGINT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL,
    bestaetigt_am DATETIME NULL,
    bestaetigt_von BIGINT UNSIGNED NULL,
    anzahl INT UNSIGNED NOT NULL DEFAULT 0,
    summe_cent BIGINT NOT NULL DEFAULT 0,
    -- pain.008 MsgId, max. 35 characters.
    msg_id VARCHAR(35) NOT NULL DEFAULT '',
    -- NULL only for the moment between creating the row (its id goes into
    -- the EndToEndId) and storing the file.
    datei_blob_id BIGINT UNSIGNED NULL,
    -- Transactions as shown in the archive, a copy independent of the members.
    daten JSON NOT NULL,
    KEY idx_einzug_erstellt (erstellt_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jahresbeitrag (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mitglied_id BIGINT UNSIGNED NOT NULL,
    jahr SMALLINT UNSIGNED NOT NULL,
    -- Copied from the fee type when created; later changes of the fee type
    -- do not touch it.
    betrag_cent INT NOT NULL,
    -- App\Domain\JahresbeitragStatus: offen | im_einzug | bezahlt.
    status VARCHAR(16) NOT NULL DEFAULT 'offen',
    einzug_id BIGINT UNSIGNED NULL,
    -- App\Domain\Zahlweise: bar | ueberweisung | sonstiges - paid by hand.
    zahlweise VARCHAR(16) NULL,
    bezahlt_am DATE NULL,
    -- List of {von, am, text} (App\Domain\Vermerk).
    vermerke JSON NOT NULL,
    UNIQUE KEY uq_jahresbeitrag_mitglied_jahr (mitglied_id, jahr),
    KEY idx_jahresbeitrag_jahr_status (jahr, status),
    KEY idx_jahresbeitrag_einzug (einzug_id),
    CONSTRAINT fk_jahresbeitrag_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE,
    CONSTRAINT fk_jahresbeitrag_einzug FOREIGN KEY (einzug_id) REFERENCES einzug (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
