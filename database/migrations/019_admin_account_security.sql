-- Phase 18: admins can change and recover their own password, and sign out everywhere.
ALTER TABLE people
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash,
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_changed_at;   -- bumped on a password change or "sign out everywhere": older sign-ins stop working

-- One-time password reset links. Only a hash of the link's secret is stored.
CREATE TABLE admin_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id   BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_tokens_hash (token_hash),
  KEY ix_admin_tokens_person (person_id, created_at),
  CONSTRAINT fk_admin_tokens_person FOREIGN KEY (person_id) REFERENCES people(id)
) ENGINE=InnoDB;