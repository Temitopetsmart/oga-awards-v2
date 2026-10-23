-- Seeds permissions and the 9 role templates, taken from V2's permissions.js.
-- The 'super' role is locked: Super admins get everything in code, so it needs no rows below.

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('dashboard.view', 'Overview', 'View the dashboard', 'read', 'edition'),
  ('nominees.view', 'Nominees', 'View nominees', 'read', 'edition'),
  ('nominees.create', 'Nominees', 'Add nominees', 'write', 'edition'),
  ('nominees.edit', 'Nominees', 'Edit nominees', 'write', 'edition'),
  ('nominees.approve', 'Nominees', 'Approve and reject nominees', 'write', 'edition'),
  ('nominees.delete', 'Nominees', 'Delete nominees', 'write', 'edition'),
  ('nominees.export', 'Nominees', 'Export nominees', 'read', 'edition'),
  ('categories.view', 'Categories', 'View categories', 'read', 'edition'),
  ('categories.manage', 'Categories', 'Add, edit and delete categories', 'write', 'edition'),
  ('votes.view', 'Votes', 'View the vote ledger', 'read', 'edition'),
  ('votes.addManual', 'Votes', 'Add manual votes', 'write', 'edition'),
  ('votes.void', 'Votes', 'Void vote orders', 'write', 'edition'),
  ('votes.export', 'Votes', 'Export votes', 'read', 'edition'),
  ('results.view', 'Results', 'View results', 'read', 'edition'),
  ('results.save', 'Results', 'Save winners', 'write', 'edition'),
  ('finance.view', 'Finance', 'See payment amounts and revenue', 'read', 'edition'),
  ('finance.export', 'Finance', 'Export financial data', 'read', 'edition'),
  ('finance.reconcile', 'Finance', 'Mark payments as paid', 'write', 'edition'),
  ('tickets.view', 'Tickets', 'View ticket orders and tickets', 'read', 'edition'),
  ('tickets.manageTypes', 'Tickets', 'Manage ticket types', 'write', 'edition'),
  ('tickets.override', 'Tickets', 'Override ticket check-in', 'write', 'edition'),
  ('tickets.export', 'Tickets', 'Export ticket orders', 'read', 'edition'),
  ('scanner.use', 'Gate scanner', 'Scan tickets at the gate', 'write', 'edition'),
  ('committee.view', 'Committee', 'View the committee and applications', 'read', 'edition'),
  ('committee.manage', 'Committee', 'Manage committee members', 'write', 'edition'),
  ('committee.review', 'Committee', 'Review committee applications', 'write', 'edition'),
  ('messages.view', 'Communications', 'Read messages', 'read', 'edition'),
  ('messages.manage', 'Communications', 'Reply to, mark and delete messages', 'write', 'edition'),
  ('occasions.view', 'Content', 'View special occasions', 'read', 'edition'),
  ('occasions.manage', 'Content', 'Manage special occasions', 'write', 'edition'),
  ('settings.view', 'Settings', 'View edition settings', 'read', 'edition'),
  ('settings.edit', 'Settings', 'Change edition settings', 'write', 'edition'),
  ('editions.view', 'Editions', 'View editions', 'read', 'global'),
  ('editions.manage', 'Editions', 'Create and manage editions', 'write', 'global'),
  ('team.view', 'Team & access', 'View people, roles and access', 'read', 'global'),
  ('team.manage', 'Team & access', 'Manage people and give access', 'write', 'global'),
  ('roles.manage', 'Team & access', 'Create and edit roles', 'write', 'global'),
  ('audit.view', 'Audit', 'View the audit log', 'read', 'global');

INSERT INTO roles (slug, name, description, is_system, is_locked) VALUES
  ('super', 'Super admin', 'Full access to everything, in every edition. Granted to a person in People.', 1, 1),
  ('editor', 'Editor', 'Runs nominations and content day to day.', 1, 0),
  ('publicity', 'Director of Publicity', 'Public content, special occasions and communications.', 1, 0),
  ('treasurer', 'Treasurer', 'Financial records, payments and exports.', 1, 0),
  ('secretary', 'Secretary', 'Committee records, applications and communications.', 1, 0),
  ('nominations', 'Nominations officer', 'Nominees and categories.', 1, 0),
  ('ticketing', 'Ticketing officer', 'Tickets and gate check-in.', 1, 0),
  ('gate', 'Gate staff', 'Scans tickets at the gate — nothing else.', 1, 0),
  ('auditor', 'Auditor', 'Read-only access to everything, including finance and the audit log.', 1, 0);

-- editor
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'editor'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'nominees.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'nominees.create'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'nominees.edit'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'nominees.approve'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'categories.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'categories.manage'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'votes.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'results.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'committee.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'messages.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'messages.manage'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'occasions.view'),
  ((SELECT id FROM roles WHERE slug = 'editor'), 'occasions.manage');

-- publicity
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'nominees.view'),
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'categories.view'),
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'results.view'),
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'messages.view'),
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'occasions.view'),
  ((SELECT id FROM roles WHERE slug = 'publicity'), 'occasions.manage');

-- treasurer
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'votes.view'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'votes.export'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'finance.view'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'finance.export'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'finance.reconcile'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'tickets.view'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'tickets.export'),
  ((SELECT id FROM roles WHERE slug = 'treasurer'), 'results.view');

-- secretary
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'secretary'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'secretary'), 'committee.view'),
  ((SELECT id FROM roles WHERE slug = 'secretary'), 'committee.manage'),
  ((SELECT id FROM roles WHERE slug = 'secretary'), 'committee.review'),
  ((SELECT id FROM roles WHERE slug = 'secretary'), 'messages.view'),
  ((SELECT id FROM roles WHERE slug = 'secretary'), 'messages.manage');

-- nominations
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'nominees.view'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'nominees.create'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'nominees.edit'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'nominees.approve'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'nominees.export'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'categories.view'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'categories.manage'),
  ((SELECT id FROM roles WHERE slug = 'nominations'), 'messages.view');

-- ticketing
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'ticketing'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'ticketing'), 'tickets.view'),
  ((SELECT id FROM roles WHERE slug = 'ticketing'), 'tickets.manageTypes'),
  ((SELECT id FROM roles WHERE slug = 'ticketing'), 'tickets.override'),
  ((SELECT id FROM roles WHERE slug = 'ticketing'), 'tickets.export'),
  ((SELECT id FROM roles WHERE slug = 'ticketing'), 'scanner.use');

-- gate
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'gate'), 'scanner.use');

-- auditor
INSERT INTO role_permissions (role_id, perm_key) VALUES
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'nominees.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'nominees.export'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'categories.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'votes.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'votes.export'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'results.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'finance.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'finance.export'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'tickets.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'tickets.export'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'committee.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'messages.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'occasions.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'settings.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'editions.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'team.view'),
  ((SELECT id FROM roles WHERE slug = 'auditor'), 'audit.view');