-- The Auditor role is "read-only access to everything", but four read permissions added in later phases were never granted to it.
INSERT IGNORE INTO role_permissions (role_id, perm_key)
SELECT r.id, p.perm_key
  FROM roles r
  JOIN (SELECT 'announcements.view' AS perm_key UNION ALL SELECT 'spotlight.view' UNION ALL SELECT 'content.view' UNION ALL SELECT 'fraud.view') p
 WHERE r.slug = 'auditor';