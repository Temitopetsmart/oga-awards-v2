-- Phase 8: one nominee can enter several categories in the same edition (one entry per category),
-- and pays the registration fee only once per edition.
ALTER TABLE nominee_entries
    ADD UNIQUE KEY uq_entry_account_edition_cat (account_id, edition_id, category_id),
    DROP INDEX uq_entry_account_edition;

ALTER TABLE edition_settings
    ADD COLUMN max_categories_per_nominee TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER nominee_registration_fee;