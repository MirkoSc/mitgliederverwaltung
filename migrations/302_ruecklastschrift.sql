-- Returned direct debits per annual fee (issue #133, docs/spec/08 section 2):
-- the day the last one was noted. Set only when a fee paid by direct debit
-- goes back to "offen", kept when it is paid later - the member page lists
-- the years, and the next run does not preselect the fee while it is open.
-- Older code ignores the column (03 section 4).
ALTER TABLE jahresbeitrag ADD COLUMN ruecklastschrift_am DATE NULL AFTER bezahlt_am;
