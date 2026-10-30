-- The geocoder now only accepts a hit with the address's postcode (issue
-- #108, App\Service\Karte\Geocoder::passt()): Nominatim answered a street
-- it does not know in the town with the same street anywhere in Germany
-- ("Am Hang", 63867 Johannesberg, ended up in 51515 Kürten). Every result
-- found so far may be such a wrong hit, and every "not found" may now be
-- found at least approximately (status 'ungefaehr', the place without the
-- street). So all automatic results are forgotten and looked up again by
-- the cron and the admin's button:
--   - a row with a search address given by hand (issue #106) keeps it and
--     counts as not asked yet (status NULL) - the next run asks with it;
--   - every other row goes.
-- Points set by hand (status 'von_hand') do not exist before this version.
--
-- No schema change: status VARCHAR(16) holds the new values 'ungefaehr'
-- and 'von_hand'. Code of the previous version reads them as unknown and
-- asks again - compatible one version back.
UPDATE geo_anschrift
SET status = NULL, lat = NULL, lon = NULL
WHERE status IN ('gefunden', 'nicht_gefunden')
  AND (such_strasse IS NOT NULL OR such_plz IS NOT NULL OR such_ort IS NOT NULL);

DELETE FROM geo_anschrift WHERE status IN ('gefunden', 'nicht_gefunden');
