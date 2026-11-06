-- The geocoder now tells a house from a street (issue #135,
-- App\Service\Karte\Geocoder): where OpenStreetMap has no house numbers,
-- Nominatim answers with the street, which was stored as "gefunden" and
-- looked exact. Every automatic "gefunden" is forgotten and looked up again
-- by the cron and the admin's button; it becomes "gefunden" (the house) or
-- the new "strasse" (drawn like an approximate dot).
--   - a row with a search address given by hand (issue #106) keeps it and
--     counts as not asked yet (status NULL);
--   - every other such row goes.
-- "ungefaehr", "nicht_gefunden" and points set by hand stay.
--
-- No schema change: status VARCHAR(16) holds the new value 'strasse'. Code
-- of the previous version reads it as unknown and asks again - compatible
-- one version back.
UPDATE geo_anschrift
SET status = NULL, lat = NULL, lon = NULL
WHERE status = 'gefunden'
  AND (such_strasse IS NOT NULL OR such_plz IS NOT NULL OR such_ort IS NOT NULL);

DELETE FROM geo_anschrift WHERE status = 'gefunden';
