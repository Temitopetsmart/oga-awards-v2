-- Registration is now one category at a time, and the fee is per category (nominee_registration_fee is the fee for ONE category).
-- Name, phone, department, level and nickname belong to the person; the bio and photo belong to each category entry.
-- By default a later entry follows the first one (the "main" entry). A flag records that a nominee changed it for that category.
ALTER TABLE nominee_entries
  ADD COLUMN bio_custom   TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN photo_custom TINYINT(1) NOT NULL DEFAULT 0;