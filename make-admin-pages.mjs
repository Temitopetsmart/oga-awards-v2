// Run from the project root:  node make-admin-pages.mjs
// Creates the admin HTML pages (admin/*.html) from one template.
import { mkdirSync, writeFileSync } from "node:fs";

const ADMINLTE = "4.10.0";

const template = ({ title, page, shell }) => `<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>${title} — OGA Admin</title>
  <meta name="theme-color" content="#0a0a0a">
  <link rel="icon" href="../assets/images/logo/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@${ADMINLTE}/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css">
  <link rel="stylesheet" href="../shared/css/tokens.css">
  <link rel="stylesheet" href="../shared/css/base.css">
  <link rel="stylesheet" href="../shared/css/components.css">
  <link rel="stylesheet" href="css/admin.css">
</head>
<body${shell ? ' class="layout-fixed sidebar-expand-lg bg-body-tertiary"' : ""} data-page="${page}">
  <div id="admin-root"></div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
${shell ? `  <script src="https://cdn.jsdelivr.net/npm/admin-lte@${ADMINLTE}/dist/js/adminlte.min.js"></script>\n` : ""}  <script type="module" src="js/main.js"></script>
</body>
</html>
`;

// [file, page module, title, uses the AdminLTE shell?]
const pages = [
  ['login', 'login', 'Sign in', false],
  ['index', 'dashboard', 'Dashboard', true],
  ['nominees', 'nominees', 'Nominees', true],
  ['categories', 'categories', 'Categories', true],
  ['votes', 'votes', 'Votes', true],
  ['results', 'results', 'Results', true],
  ['tickets', 'tickets', 'Tickets', true],
  ['scanner', 'scanner', 'Gate scanner', true],
  ['occasions', 'occasions', 'Occasions', true],
  ['committee', 'committee', 'Committee', true],
  ['messages', 'messages', 'Messages', true],
  ['settings', 'settings', 'Settings', true],
  ['admins', 'admins', 'Admins & roles', true],
  ['audit', 'audit', 'Audit log', true],
];

mkdirSync("admin", { recursive: true });
for (const [file, page, title, shell] of pages)
  writeFileSync(`admin/${file}.html`, template({ title, page, shell }));
console.log(`Created ${pages.length} admin pages.`);
