-- Phase 14: announcements (public, or for nominees only).
CREATE TABLE announcements (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(150) NOT NULL,
  body        TEXT NOT NULL,
  audience    ENUM('public','nominees') NOT NULL DEFAULT 'public',
  pinned      TINYINT(1) NOT NULL DEFAULT 0,
  status      ENUM('draft','published') NOT NULL DEFAULT 'published',
  publish_at  DATETIME NULL,                 -- visible from this moment (NULL = as soon as published)
  expires_at  DATETIME NULL,                 -- hidden after this moment (NULL = never)
  created_by  BIGINT UNSIGNED NULL,
  updated_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_ann_visible (edition_id, status, audience, publish_at),
  CONSTRAINT fk_ann_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_ann_created FOREIGN KEY (created_by) REFERENCES people(id),
  CONSTRAINT fk_ann_updated FOREIGN KEY (updated_by) REFERENCES people(id)
) ENGINE=InnoDB;

-- Phase 15: Face of the period (a spotlight on one approved nominee for a month, week or any period the admin names).
CREATE TABLE face_of_period (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id       BIGINT UNSIGNED NOT NULL,
  nominee_entry_id BIGINT UNSIGNED NOT NULL,
  period_label     VARCHAR(60) NOT NULL,     -- e.g. "November 2026" or "Week 3"
  headline         VARCHAR(120) NULL,
  note             VARCHAR(500) NULL,
  featured         TINYINT(1) NOT NULL DEFAULT 0,   -- the one shown on the home page
  created_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_face_period (edition_id, period_label),
  KEY ix_face_featured (featured, id),
  CONSTRAINT fk_face_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_face_nominee FOREIGN KEY (nominee_entry_id) REFERENCES nominee_entries(id),
  CONSTRAINT fk_face_by FOREIGN KEY (created_by) REFERENCES people(id)
) ENGINE=InnoDB;

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('announcements.view',   'Content', 'View announcements',            'read',  'edition'),
  ('announcements.manage', 'Content', 'Manage announcements',          'write', 'edition'),
  ('spotlight.view',       'Content', 'View Face of the period',       'read',  'edition'),
  ('spotlight.manage',     'Content', 'Manage Face of the period',     'write', 'edition');

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, p.k FROM roles r
  JOIN (SELECT 'announcements.view' AS k UNION ALL SELECT 'announcements.manage'
        UNION ALL SELECT 'spotlight.view' UNION ALL SELECT 'spotlight.manage') p
  WHERE r.slug IN ('editor', 'publicity');