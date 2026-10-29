-- Members, families and member versions (docs/spec/07-mitglieder.md
-- sections 1, 3, 7 and 8). Plaintext columns (E-11): search, filter and sort
-- run in SQL.
--
-- Optional text fields are NOT NULL DEFAULT '': "missing" is the empty
-- string, one case instead of two. Optional dates are NULL.
--
-- User ids (geaendert_von ...) carry no foreign key: the `user` table belongs
-- to another migration range, and the history must outlive a deleted
-- account (like audit_log, docs/spec/03-datenhaltung.md section 2).

CREATE TABLE familie (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    -- FamID of the old software (docs/spec/10-import.md section 4), what the
    -- reconciliation import matches families by. NULL for families created
    -- in the application.
    import_schluessel VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_familie_import_schluessel (import_schluessel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mitglied (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Whole number, unique, changeable; imported members keep their old
    -- "Adressnummer".
    mitgliedsnummer INT UNSIGNED NOT NULL,
    anrede VARCHAR(32) NOT NULL DEFAULT '',
    titel VARCHAR(64) NOT NULL DEFAULT '',
    vorname VARCHAR(100) NOT NULL DEFAULT '',
    nachname VARCHAR(100) NOT NULL,
    namenszusatz VARCHAR(64) NOT NULL DEFAULT '',
    -- App\Domain\Geschlecht: m | w | d, NULL = unknown.
    geschlecht CHAR(1) NULL,
    geburtsdatum DATE NULL,
    strasse VARCHAR(150) NOT NULL DEFAULT '',
    plz VARCHAR(10) NOT NULL DEFAULT '',
    ort VARCHAR(100) NOT NULL DEFAULT '',
    land VARCHAR(3) NOT NULL DEFAULT 'D',
    telefon VARCHAR(50) NOT NULL DEFAULT '',
    handy VARCHAR(50) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    -- NULL = "unbekannt".
    eintrittsdatum DATE NULL,
    -- "Ehemalig" is computed (austrittsdatum <= today), never stored.
    austrittsdatum DATE NULL,
    austrittsgrund VARCHAR(255) NOT NULL DEFAULT '',
    -- 1 = aktiv, 0 = passiv.
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    beitragsart_id BIGINT UNSIGNED NULL,
    -- App\Domain\Zahlart: lastschrift | selbst.
    zahlart VARCHAR(16) NOT NULL DEFAULT 'lastschrift',
    -- Normalised on save (upper case, no spaces); an invalid one is kept
    -- and only gives a hint.
    iban VARCHAR(34) NOT NULL DEFAULT '',
    bic VARCHAR(11) NOT NULL DEFAULT '',
    -- Only when it differs from the member.
    kontoinhaber VARCHAR(140) NOT NULL DEFAULT '',
    mandatsreferenz VARCHAR(35) NOT NULL DEFAULT '',
    mandatsdatum DATE NULL,
    familie_id BIGINT UNSIGNED NULL,
    -- App\Domain\FamilienRolle: zahler | elternteil | kind.
    familie_rolle VARCHAR(16) NULL,
    lizenznummer VARCHAR(64) NOT NULL DEFAULT '',
    bemerkung TEXT NOT NULL DEFAULT '',
    -- App\Domain\Herkunft: import | manuell (docs/spec/10-import.md).
    herkunft VARCHAR(16) NOT NULL DEFAULT 'manuell',
    -- Number of the latest row in mitglied_version; also the optimistic
    -- lock of App\Repository\MitgliedRepository::speichern().
    version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_mitglied_nummer (mitgliedsnummer),
    KEY idx_mitglied_name (nachname, vorname),
    KEY idx_mitglied_austritt (austrittsdatum),
    CONSTRAINT fk_mitglied_beitragsart FOREIGN KEY (beitragsart_id) REFERENCES beitragsart (id),
    CONSTRAINT fk_mitglied_familie FOREIGN KEY (familie_id) REFERENCES familie (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mitglied_abteilung (
    mitglied_id BIGINT UNSIGNED NOT NULL,
    abteilung_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (mitglied_id, abteilung_id),
    KEY idx_mitglied_abteilung_abteilung (abteilung_id),
    CONSTRAINT fk_mitglied_abteilung_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE,
    CONSTRAINT fk_mitglied_abteilung_abteilung FOREIGN KEY (abteilung_id) REFERENCES abteilung (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mitglied_funktion (
    mitglied_id BIGINT UNSIGNED NOT NULL,
    funktion_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (mitglied_id, funktion_id),
    KEY idx_mitglied_funktion_funktion (funktion_id),
    CONSTRAINT fk_mitglied_funktion_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE,
    CONSTRAINT fk_mitglied_funktion_funktion FOREIGN KEY (funktion_id) REFERENCES funktion (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per save that changed something (07 section 7): the complete
-- state of the member (App\Domain\Mitglied::daten()), who, when and why.
-- Deleted together with the member.
CREATE TABLE mitglied_version (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mitglied_id BIGINT UNSIGNED NOT NULL,
    nr INT UNSIGNED NOT NULL,
    -- angelegt | geaendert | importiert | abgleich |
    -- "wiederhergestellt aus Version n" (App\Domain\VersionsAnlass).
    anlass VARCHAR(64) NOT NULL,
    geaendert_von BIGINT UNSIGNED NULL,
    geaendert_am DATETIME NOT NULL,
    daten JSON NOT NULL,
    UNIQUE KEY uq_mitglied_version_nr (mitglied_id, nr),
    CONSTRAINT fk_mitglied_version_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
