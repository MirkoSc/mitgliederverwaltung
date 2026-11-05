-- "Anrede" was dropped in v0.4.0 (issue #127, docs/spec/07 section 1): since
-- then no code reads or writes the column. Removed one release later, as
-- 03 section 4 asks, so v0.4.0 still runs on this schema (issue #129).
-- Old rows of mitglied_version keep "anrede" in their JSON; it is ignored.
ALTER TABLE mitglied DROP COLUMN anrede;
