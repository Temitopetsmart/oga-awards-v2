-- Phase 2: Settings hub. Encrypted secrets and admin-managed lists (levels, departments).

CREATE TABLE app_secrets (
    secret_key  VARCHAR(60) NOT NULL PRIMARY KEY,
    ciphertext  TEXT NOT NULL,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE lookup_options (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind        ENUM('level', 'department') NOT NULL,
    name        VARCHAR(120) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_option (kind, name),
    KEY idx_option_list (kind, active, sort_order)
) ENGINE=InnoDB;

INSERT INTO lookup_options (kind, name, sort_order) VALUES
    ('level', 'ND I', 1),
    ('level', 'ND II', 2),
    ('level', 'HND I', 3),
    ('level', 'HND II', 4),
    ('level', 'Staff', 5),
    ('level', 'Alumni', 6);