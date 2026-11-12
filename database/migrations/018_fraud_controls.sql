-- Phase 17: anti-fraud. Where a vote order came from, what the admin considers suspicious,
-- who is blocked, and what has been reviewed.
ALTER TABLE vote_orders
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER voter_email,
    ADD KEY ix_vo_ip (edition_id, ip_address, created_at);

ALTER TABLE edition_settings
    ADD COLUMN fraud_rules JSON NULL;

CREATE TABLE vote_blocks (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NOT NULL,
  kind        ENUM('ip','email','domain') NOT NULL,
  value       VARCHAR(190) NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vb (edition_id, kind, value),
  CONSTRAINT fk_vote_blocks_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_vote_blocks_by FOREIGN KEY (created_by) REFERENCES people(id)
) ENGINE=InnoDB;

CREATE TABLE fraud_reviews (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id   BIGINT UNSIGNED NOT NULL,
  signal_key   VARCHAR(190) NOT NULL,
  status       ENUM('dismissed','actioned') NOT NULL,
  note         VARCHAR(255) NULL,
  reviewed_by  BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fr (edition_id, signal_key),
  CONSTRAINT fk_fraud_reviews_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_fraud_reviews_by FOREIGN KEY (reviewed_by) REFERENCES people(id)
) ENGINE=InnoDB;

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('fraud.view',   'Votes', 'View fraud signals and blocks',          'read',  'edition'),
  ('fraud.manage', 'Votes', 'Review signals, set rules, block voters', 'write', 'edition');

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, 'fraud.view' FROM roles r WHERE r.slug IN ('treasurer', 'auditor');