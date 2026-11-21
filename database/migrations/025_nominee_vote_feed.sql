-- Nominee vote feed: lets a nominee see their own vote total and the latest votes in the portal.
-- OFF by default (the admin switches it on per edition). Rankings and other nominees' counts are never shown.
ALTER TABLE edition_settings
    ADD COLUMN vote_feed_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER flier_config,
    ADD COLUMN vote_feed_names ENUM('full','masked','anonymous') NOT NULL DEFAULT 'masked' AFTER vote_feed_enabled,
    ADD COLUMN vote_feed_recent TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER vote_feed_names;