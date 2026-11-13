-- Phase 19: importing a past edition (for example OGA 9.0). Super admins only until someone is given the permission.
INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('import.run', 'Administration', 'Import a past edition', 'write', 'global');