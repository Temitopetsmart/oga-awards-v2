/**
 * Admin menu. A page shows only when the signed-in person has the permission in `perm`
 * (checked in the edition being viewed). Roles and permissions themselves live in
 * permissions.js and the team store, not here.
 */
export const ADMIN = {
  title: "OGA Admin",
  menu: [
    { section: "Overview" },
    {
      label: "Dashboard",
      icon: "fa-gauge-high",
      href: "admin/",
      perm: "dashboard.view",
    },
    { section: "Awards" },
    {
      label: "Nominees",
      icon: "fa-user-group",
      href: "admin/nominees",
      perm: "nominees.view",
      badge: "pendingNominees",
    },
    {
      label: "Categories",
      icon: "fa-layer-group",
      href: "admin/categories",
      perm: "categories.view",
    },
    {
      label: "Votes",
      icon: "fa-check-to-slot",
      href: "admin/votes",
      perm: "votes.view",
    },
    {
      label: "Results",
      icon: "fa-trophy",
      href: "admin/results",
      perm: "results.view",
    },
    {
      label: "Fraud checks",
      icon: "fa-shield-halved",
      href: "admin/fraud",
      perm: "fraud.view",
    },
    { section: "Content" },
    {
      label: "Announcements",
      icon: "fa-bullhorn",
      href: "admin/announcements",
      perm: "announcements.view",
    },
    {
      label: "Face of the period",
      icon: "fa-star",
      href: "admin/spotlight",
      perm: "spotlight.view",
    },
    {
      label: "Site content",
      icon: "fa-pen-to-square",
      href: "admin/sitecontent",
      perm: "content.view",
    },
    { section: "Events" },
    {
      label: "Tickets",
      icon: "fa-ticket",
      href: "admin/tickets",
      perm: "tickets.view",
    },
    {
      label: "Gate scanner",
      icon: "fa-qrcode",
      href: "admin/scanner",
      perm: "scanner.use",
    },
    {
      label: "Occasions",
      icon: "fa-crown",
      href: "admin/occasions",
      perm: "occasions.view",
    },
    { section: "People" },
    {
      label: "Committee",
      icon: "fa-people-group",
      href: "admin/committee",
      perm: "committee.view",
      badge: "newApplications",
    },
    {
      label: "Messages",
      icon: "fa-envelope",
      href: "admin/messages",
      perm: "messages.view",
      badge: "unreadMessages",
    },
    { section: "Administration" },
    {
      label: "Editions",
      icon: "fa-clock-rotate-left",
      href: "admin/editions",
      perm: "editions.view",
    },
    {
      label: "Team & access",
      icon: "fa-user-shield",
      href: "admin/team",
      perm: "team.view",
    },
    {
      label: "Roles & permissions",
      icon: "fa-key",
      href: "admin/roles",
      perm: "team.view",
    },
    {
      label: "Email",
      icon: "fa-paper-plane",
      href: "admin/email",
      perm: "settings.view",
    },
    {
      label: "Settings",
      icon: "fa-sliders",
      href: "admin/settings",
      perm: "settings.view",
    },
    {
      label: "Audit log",
      icon: "fa-list-check",
      href: "admin/audit",
      perm: "audit.view",
    },
    {
      label: "Import a past edition",
      icon: "fa-file-import",
      href: "admin/import",
      perm: "import.run",
    },
    {
      label: "My account",
      icon: "fa-user-gear",
      href: "admin/account",
    },
  ],
};
