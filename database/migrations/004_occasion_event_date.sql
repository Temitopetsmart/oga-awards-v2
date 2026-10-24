-- Optional event date for a special occasion (shown under "Key dates" on its page).
ALTER TABLE occasions ADD COLUMN event_at DATETIME NULL AFTER description;