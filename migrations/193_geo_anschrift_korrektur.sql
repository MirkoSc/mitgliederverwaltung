-- A search address for the map only (issue #106,
-- docs/spec/03-datenhaltung.md section 2): when the geocoder does not find
-- a member's address, or finds it in the wrong place, somebody with
-- mitglieder.bearbeiten can give street, postcode and town to search with
-- instead. It belongs to the address (its hash), not to the member: a
-- changed address has another hash and is looked up normally again, and
-- the row goes with the next clean-up once nobody uses the old address.
-- The member's own address stays untouched.
--
-- status becomes NULL-able: a row with a search address that could not be
-- asked yet (the service rested or failed) is "not asked yet", and the next
-- run asks with the search address. Code of the previous version reads such
-- a row as unknown and simply asks again - compatible one version back.
ALTER TABLE geo_anschrift
    MODIFY status VARCHAR(16) NULL,
    ADD COLUMN such_strasse VARCHAR(150) NULL,
    ADD COLUMN such_plz VARCHAR(10) NULL,
    ADD COLUMN such_ort VARCHAR(100) NULL;
