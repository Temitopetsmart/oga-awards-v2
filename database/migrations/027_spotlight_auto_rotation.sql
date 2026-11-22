-- Face of the moment: the site shows one random nominee or committee member at a time, and changes by itself
-- every N seconds, minutes, hours, days, weeks or months (all set by the admin, per edition). OFF by default.
ALTER TABLE edition_settings
    ADD COLUMN spotlight_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER vote_feed_recent,
    ADD COLUMN spotlight_every SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER spotlight_auto,
    ADD COLUMN spotlight_unit ENUM('second','minute','hour','day','week','month') NOT NULL DEFAULT 'day' AFTER spotlight_every,
    ADD COLUMN spotlight_who ENUM('both','nominees','committee') NOT NULL DEFAULT 'both' AFTER spotlight_unit,
    ADD COLUMN spotlight_show_bio TINYINT(1) NOT NULL DEFAULT 1 AFTER spotlight_who,
    ADD COLUMN spotlight_require_photo TINYINT(1) NOT NULL DEFAULT 1 AFTER spotlight_show_bio,
    ADD COLUMN spotlight_heading VARCHAR(80) NOT NULL DEFAULT 'Meet the face of the {period}' AFTER spotlight_require_photo,
    ADD COLUMN spotlight_tagline VARCHAR(160) NOT NULL DEFAULT 'Could it be you next? Stay tuned.' AFTER spotlight_heading;

-- Who was shown in which time slot. Once a slot has a person, everybody sees that same person until the slot ends,
-- and nobody comes round again until everyone else has had a turn.
CREATE TABLE spotlight_slots (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    edition_id   BIGINT UNSIGNED NOT NULL,
    slot_key     VARCHAR(40) NOT NULL,
    round_no     INT UNSIGNED NOT NULL DEFAULT 1,
    subject_type ENUM('nominee','committee') NOT NULL,
    subject_id   BIGINT UNSIGNED NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slot (edition_id, slot_key),
    KEY ix_slot_recent (edition_id, id),
    KEY ix_slot_round (edition_id, round_no),
    CONSTRAINT fk_slot_edition FOREIGN KEY (edition_id) REFERENCES editions(id)
) ENGINE=InnoDB;