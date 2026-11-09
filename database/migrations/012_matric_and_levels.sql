-- Phase 7: matric numbers (unique per student across the platform), levels that decide who may enter a category,
-- and the tool that moves students up a level.

-- A student's matric number and current level live on their nominee account. A student with no account yet
-- (a committee applicant) carries the matric number on the application.
ALTER TABLE accounts
    ADD COLUMN matric_no VARCHAR(30) NULL AFTER email,
    ADD COLUMN level VARCHAR(30) NULL AFTER matric_no,
    ADD UNIQUE KEY uq_acc_matric (matric_no);
ALTER TABLE committee_applications ADD COLUMN matric_no VARCHAR(30) NULL AFTER email, ADD KEY ix_ca_matric (matric_no);

-- One matric number belongs to one email address and one email address has one matric number, across nominee
-- accounts and committee applications alike. Claiming a number that is taken by someone else is refused.
CREATE TABLE matric_registry (
    matric_no  VARCHAR(30)  NOT NULL PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    source     ENUM('nominee', 'committee', 'admin') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mr_email (email)
) ENGINE=InnoDB;

-- Which levels must give a matric number (staff have none). Set in Admin → Settings → Levels.
ALTER TABLE lookup_options ADD COLUMN requires_matric TINYINT(1) NOT NULL DEFAULT 1;
UPDATE lookup_options SET requires_matric = 0 WHERE kind = 'level' AND name = 'Staff';

-- Who may be nominated in a category. No rows = everyone ("general").
CREATE TABLE category_levels (
    category_id BIGINT UNSIGNED NOT NULL,
    level       VARCHAR(30) NOT NULL,
    PRIMARY KEY (category_id, level),
    CONSTRAINT fk_cl_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB;

-- Every time the admin moves students up a level.
CREATE TABLE level_promotions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applied_by BIGINT UNSIGNED NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    mapping    TEXT NOT NULL,
    moved      INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Existing accounts: their current level is the level on their newest entry.
UPDATE accounts a
JOIN nominee_entries n ON n.id = (SELECT MAX(id) FROM nominee_entries WHERE account_id = a.id AND level IS NOT NULL)
SET a.level = n.level;