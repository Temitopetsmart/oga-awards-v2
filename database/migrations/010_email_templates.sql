-- Phase 4: email in one place. The default wording of every email lives in code (MailTemplates::DEFAULTS);
-- a row here is the admin's own version. Deleting the row restores the default.
CREATE TABLE email_templates (
    template_key VARCHAR(60) NOT NULL PRIMARY KEY,
    subject      VARCHAR(200) NOT NULL,
    body         TEXT NOT NULL,
    updated_by   BIGINT UNSIGNED NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Outbox viewer: filter by type and find a recipient quickly.
ALTER TABLE notification_outbox ADD KEY ix_no_type (type, created_at);
ALTER TABLE notification_outbox ADD KEY ix_no_recipient (recipient);