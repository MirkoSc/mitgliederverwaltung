-- Master data the settings page maintains (docs/spec/07-mitglieder.md
-- sections 2 and 4, docs/spec/08-beitraege.md section 1), with the seeds of
-- a fresh installation. Everything after the seed is freely changeable.
--
-- Status-like columns are VARCHAR and not ENUM: the authority is the PHP
-- enum in App\Domain, and a new value must not cost an ALTER TABLE.
--
-- Deleting a row is only possible while no member refers to it (foreign
-- keys with RESTRICT, created in 041); otherwise it is deactivated.

CREATE TABLE abteilung (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    -- BLSV "Spartenkennzeichen": text, the leading zero matters ("09").
    blsv_nummer VARCHAR(8) NOT NULL DEFAULT '',
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE funktion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    -- Whether the member form asks for the licence number (Übungsleiter).
    mit_lizenz TINYINT(1) NOT NULL DEFAULT 0,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE beitragsart (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    -- Integer cent, never float (CLAUDE.md section 5). A change only affects
    -- annual fees created afterwards (08 section 1).
    betrag_cent INT NOT NULL DEFAULT 0,
    -- App\Domain\BeitragsRegel: jugend | familie | rentner | beitragsfrei,
    -- NULL = no rule. Only drives the hints (07 section 6), never a block.
    regel VARCHAR(16) NULL,
    -- App\Domain\AktivArt: aktiv | passiv | egal.
    aktiv_art VARCHAR(8) NOT NULL DEFAULT 'egal',
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO abteilung (name, blsv_nummer, sortierung, aktiv) VALUES
    ('Badminton', '01', 10, 1),
    ('Fußball', '09', 20, 1),
    ('Kegeln', '16', 30, 1),
    ('Gymnastik', '34', 40, 1);

INSERT INTO funktion (name, mit_lizenz, sortierung, aktiv) VALUES
    ('Übungsleiter', 1, 10, 1),
    ('Wirtschaftshelfer', 0, 20, 1),
    ('Bahndienst', 0, 30, 1),
    ('Jugendtrainer', 0, 40, 1);

INSERT INTO beitragsart (name, betrag_cent, regel, aktiv_art, sortierung, aktiv) VALUES
    ('Aktiv', 8500, NULL, 'aktiv', 10, 1),
    ('Passiv', 5000, NULL, 'passiv', 20, 1),
    ('Jugend/Schüler/Studenten/Azubis', 4500, 'jugend', 'egal', 30, 1),
    ('Familie', 13000, 'familie', 'egal', 40, 1),
    ('Rentner aktiv', 5000, 'rentner', 'aktiv', 50, 1),
    ('Rentner passiv', 3000, 'rentner', 'passiv', 60, 1),
    ('Mindestbeitrag', 2500, NULL, 'egal', 70, 1),
    ('Beitragsfrei', 0, 'beitragsfrei', 'egal', 80, 1);
