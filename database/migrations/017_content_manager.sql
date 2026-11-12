-- Phase 16: the content manager. Site text lives in the database once an admin edits it;
-- until then the original files in /data are used. Delete a row to go back to the file.
CREATE TABLE site_content (
  name        VARCHAR(30) NOT NULL PRIMARY KEY,
  content     LONGTEXT NOT NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_site_content_by FOREIGN KEY (updated_by) REFERENCES people(id)
) ENGINE=InnoDB;

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('content.view', 'Content', 'View site content', 'read',  'global'),
  ('content.edit', 'Content', 'Edit site content', 'write', 'global');

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, p.k FROM roles r
  JOIN (SELECT 'content.view' AS k UNION ALL SELECT 'content.edit') p
  WHERE r.slug IN ('editor', 'publicity');