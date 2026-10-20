/**
 * The permission catalogue — the single source of truth for what can be allowed.
 * key:   area.action        kind: 'read' (view/export) or 'write' (anything that changes data)
 * scope: 'edition' = applies inside one edition (blocked when that edition is completed/archived)
 *        'global'  = applies across editions (people, roles, editions, audit)
 * The PHP backend will use these same keys and the same rule (see access.js) on every request.
 */
const P = (area, key, label, kind = "write", scope = "edition") => ({
  area,
  key,
  label,
  kind,
  scope,
});

export const PERMISSIONS = [
  P("Overview", "dashboard.view", "View the dashboard", "read"),

  P("Nominees", "nominees.view", "View nominees", "read"),
  P("Nominees", "nominees.create", "Add nominees"),
  P("Nominees", "nominees.edit", "Edit nominees"),
  P("Nominees", "nominees.approve", "Approve and reject nominees"),
  P("Nominees", "nominees.delete", "Delete nominees"),
  P("Nominees", "nominees.export", "Export nominees", "read"),

  P("Categories", "categories.view", "View categories", "read"),
  P("Categories", "categories.manage", "Add, edit and delete categories"),

  P("Votes", "votes.view", "View the vote ledger", "read"),
  P("Votes", "votes.addManual", "Add manual votes"),
  P("Votes", "votes.void", "Void vote orders"),
  P("Votes", "votes.export", "Export votes", "read"),

  P("Results", "results.view", "View results", "read"),
  P("Results", "results.save", "Save winners"),

  P("Finance", "finance.view", "See payment amounts and revenue", "read"),
  P("Finance", "finance.export", "Export financial data", "read"),
  P("Finance", "finance.reconcile", "Mark payments as paid"),

  P("Tickets", "tickets.view", "View ticket orders and tickets", "read"),
  P("Tickets", "tickets.manageTypes", "Manage ticket types"),
  P("Tickets", "tickets.override", "Override ticket check-in"),
  P("Tickets", "tickets.export", "Export ticket orders", "read"),

  P("Gate scanner", "scanner.use", "Scan tickets at the gate"),

  P(
    "Committee",
    "committee.view",
    "View the committee and applications",
    "read",
  ),
  P("Committee", "committee.manage", "Manage committee members"),
  P("Committee", "committee.review", "Review committee applications"),

  P("Communications", "messages.view", "Read messages", "read"),
  P("Communications", "messages.manage", "Reply to, mark and delete messages"),

  P("Content", "occasions.view", "View special occasions", "read"),
  P("Content", "occasions.manage", "Manage special occasions"),

  P("Settings", "settings.view", "View edition settings", "read"),
  P("Settings", "settings.edit", "Change edition settings"),

  P("Editions", "editions.view", "View editions", "read", "global"),
  P(
    "Editions",
    "editions.manage",
    "Create and manage editions",
    "write",
    "global",
  ),

  P(
    "Team & access",
    "team.view",
    "View people, roles and access",
    "read",
    "global",
  ),
  P(
    "Team & access",
    "team.manage",
    "Manage people and give access",
    "write",
    "global",
  ),
  P(
    "Team & access",
    "roles.manage",
    "Create and edit roles",
    "write",
    "global",
  ),

  P("Audit", "audit.view", "View the audit log", "read", "global"),
];

export const PERM_BY_KEY = Object.fromEntries(
  PERMISSIONS.map((p) => [p.key, p]),
);
export const ALL_KEYS = PERMISSIONS.map((p) => p.key);
export const READ_KEYS = PERMISSIONS.filter((p) => p.kind === "read").map(
  (p) => p.key,
);
export const AREAS = [...new Set(PERMISSIONS.map((p) => p.area))];

const R = (id, name, description, perms, extra = {}) => ({
  id,
  name,
  description,
  perms,
  ...extra,
});

/** Predefined roles. Super admin is a locked, global status on a person, not an assignable role. */
export const ROLE_TEMPLATES = [
  R(
    "role-super",
    "Super admin",
    "Full access to everything, in every edition. Granted to a person in People.",
    ["*"],
    { locked: true },
  ),
  R("role-editor", "Editor", "Runs nominations and content day to day.", [
    "dashboard.view",
    "nominees.view",
    "nominees.create",
    "nominees.edit",
    "nominees.approve",
    "categories.view",
    "categories.manage",
    "votes.view",
    "results.view",
    "committee.view",
    "messages.view",
    "messages.manage",
    "occasions.view",
    "occasions.manage",
  ]),
  R(
    "role-publicity",
    "Director of Publicity",
    "Public content, special occasions and communications.",
    [
      "dashboard.view",
      "nominees.view",
      "categories.view",
      "results.view",
      "messages.view",
      "occasions.view",
      "occasions.manage",
    ],
  ),
  R("role-treasurer", "Treasurer", "Financial records, payments and exports.", [
    "dashboard.view",
    "votes.view",
    "votes.export",
    "finance.view",
    "finance.export",
    "finance.reconcile",
    "tickets.view",
    "tickets.export",
    "results.view",
  ]),
  R(
    "role-secretary",
    "Secretary",
    "Committee records, applications and communications.",
    [
      "dashboard.view",
      "committee.view",
      "committee.manage",
      "committee.review",
      "messages.view",
      "messages.manage",
    ],
  ),
  R("role-nominations", "Nominations officer", "Nominees and categories.", [
    "dashboard.view",
    "nominees.view",
    "nominees.create",
    "nominees.edit",
    "nominees.approve",
    "nominees.export",
    "categories.view",
    "categories.manage",
    "messages.view",
  ]),
  R("role-ticketing", "Ticketing officer", "Tickets and gate check-in.", [
    "dashboard.view",
    "tickets.view",
    "tickets.manageTypes",
    "tickets.override",
    "tickets.export",
    "scanner.use",
  ]),
  R("role-gate", "Gate staff", "Scans tickets at the gate — nothing else.", [
    "scanner.use",
  ]),
  R(
    "role-auditor",
    "Auditor",
    "Read-only access to everything, including finance and the audit log.",
    READ_KEYS,
  ),
];
