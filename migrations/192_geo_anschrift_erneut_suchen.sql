-- The geocoder now gets the town only up to its first minus
-- (App\Service\Karte\Anschrift::ortFuerSuche()): "Johannesberg - OAB" was
-- never found. Forget every "not found" so the cron and the admin button
-- look these addresses up again; found ones stay.
DELETE FROM geo_anschrift WHERE status = 'nicht_gefunden';
