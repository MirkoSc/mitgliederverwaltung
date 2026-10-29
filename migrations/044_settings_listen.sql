-- Default settings of the lists (docs/spec/09-listen-und-exporte.md
-- sections 2 and 6): round birthdays and honour levels, JSON lists of
-- numbers, changeable on the settings page. INSERT IGNORE: a value that is
-- already there (restored backup) wins. The timestamp is only informative.
INSERT IGNORE INTO setting (name, value, updated_at) VALUES
    ('runde_geburtstage', '[18,30,40,50,60,65,70,75,80,85,90,95,100]', NOW()),
    ('ehrungsstufen', '[25,40,50,60,70]', NOW());
