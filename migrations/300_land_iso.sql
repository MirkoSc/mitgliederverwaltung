-- The country of a member is an ISO 3166-1 alpha-2 code ("DE") instead of
-- the licence-plate code of the former software ("D"), docs/spec/07 section 1
-- (issue #127). The column stays VARCHAR(3), so the previous release still
-- reads it. geo_anschrift is not touched: App\Service\Karte\Anschrift keeps
-- "D" for Germany inside the hash, so coordinates stay valid. Old rows of
-- mitglied_version keep their "D"; App\Support\Laender::iso() reads it as DE.
ALTER TABLE mitglied ALTER COLUMN land SET DEFAULT 'DE';
UPDATE mitglied SET land = 'DE' WHERE land IN ('D', '');
UPDATE mitglied SET land = 'AT' WHERE land = 'A';
UPDATE mitglied SET land = 'FR' WHERE land = 'F';
UPDATE mitglied SET land = 'BE' WHERE land = 'B';
UPDATE mitglied SET land = 'LU' WHERE land = 'L';
UPDATE mitglied SET land = 'IT' WHERE land = 'I';
UPDATE mitglied SET land = 'ES' WHERE land = 'E';
UPDATE mitglied SET land = 'GB' WHERE land = 'UK';
UPDATE mitglied SET land = 'HU' WHERE land = 'H';
UPDATE mitglied SET land = 'LI' WHERE land = 'FL';
UPDATE mitglied SET land = 'SE' WHERE land = 'S';
UPDATE mitglied SET land = 'NO' WHERE land = 'N';
UPDATE mitglied SET land = 'PT' WHERE land = 'P';
UPDATE mitglied SET land = 'SI' WHERE land = 'SLO';
UPDATE mitglied SET land = 'US' WHERE land = 'USA';
UPDATE mitglied SET land = 'IE' WHERE land = 'IRL';
UPDATE mitglied SET land = 'FI' WHERE land = 'FIN';
UPDATE mitglied SET land = 'RU' WHERE land = 'RUS';
