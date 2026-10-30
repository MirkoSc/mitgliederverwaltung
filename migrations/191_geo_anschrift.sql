-- Coordinates of member addresses for the page "Karte" (issue #91,
-- docs/spec/03-datenhaltung.md section 2). One row per NORMALISED address,
-- keyed by its hash - not per member: a household is asked for once, and a
-- changed address simply has another hash and is looked up again. The table
-- holds no address text and no name; the members' own rows stay the only
-- place their addresses live. Rows no member uses any more are removed by
-- App\Service\Karte\Koordinatenermittlung.
CREATE TABLE geo_anschrift (
    -- SHA-256 (hex) of App\Service\Karte\Anschrift::normalisiert().
    anschrift_hash CHAR(64) NOT NULL PRIMARY KEY,
    -- gefunden | nicht_gefunden (App\Service\Karte\GeoStatus). "Not found"
    -- is remembered so the geocoder is not asked again and again.
    status VARCHAR(16) NOT NULL,
    lat DECIMAL(9,6) NULL,
    lon DECIMAL(9,6) NULL,
    geprueft_am DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
