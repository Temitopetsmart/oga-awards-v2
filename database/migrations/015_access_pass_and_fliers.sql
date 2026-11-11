-- Phase 12: the Nominee Access Pass: a free event ticket for approved nominees, using the normal ticket system.
ALTER TABLE ticket_types
    ADD COLUMN kind ENUM('public','access_pass') NOT NULL DEFAULT 'public' AFTER name;

ALTER TABLE ticket_orders
    ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER payment_id,
    ADD UNIQUE KEY uq_to_account_edition (account_id, edition_id),
    ADD CONSTRAINT fk_to_account FOREIGN KEY (account_id) REFERENCES accounts(id);

ALTER TABLE edition_settings
    ADD COLUMN access_pass_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER max_categories_per_nominee,
    ADD COLUMN access_pass_guests TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER access_pass_enabled;

-- Phase 13: the promo flier nominees download. Look and wording are set by the admin; the nickname is the nominee's.
ALTER TABLE edition_settings
    ADD COLUMN flier_config JSON NULL AFTER access_pass_guests;

ALTER TABLE nominee_entries
    ADD COLUMN nickname VARCHAR(40) NULL AFTER name;