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
      href: "admin/index.html",
      perm: "dashboard.view",
    },
    { section: "Awards" },
    {
      label: "Nominees",
      icon: "fa-user-group",
      href: "admin/nominees.html",
      perm: "nominees.view",
      badge: "pendingNominees",
    },
    {
      label: "Categories",
      icon: "fa-layer-group",
      href: "admin/categories.html",
      perm: "categories.view",
    },
    {
      label: "Votes",
      icon: "fa-check-to-slot",
      href: "admin/votes.html",
      perm: "votes.view",
    },
    {
      label: "Results",
      icon: "fa-trophy",
      href: "admin/results.html",
      perm: "results.view",
    },
    { section: "Events" },
    {
      label: "Tickets",
      icon: "fa-ticket",
      href: "admin/tickets.html",
      perm: "tickets.view",
    },
    {
      label: "Gate scanner",
      icon: "fa-qrcode",
      href: "admin/scanner.html",
      perm: "scanner.use",
    },
    {
      label: "Occasions",
      icon: "fa-crown",
      href: "admin/occasions.html",
      perm: "occasions.view",
    },
    { section: "People" },
    {
      label: "Committee",
      icon: "fa-people-group",
      href: "admin/committee.html",
      perm: "committee.view",
      badge: "newApplications",
    },
    {
      label: "Messages",
      icon: "fa-envelope",
      href: "admin/messages.html",
      perm: "messages.view",
      badge: "unreadMessages",
    },
    { section: "Administration" },
    {
      label: "Editions",
      icon: "fa-clock-rotate-left",
      href: "admin/editions.html",
      perm: "editions.view",
    },
    {
      label: "Team & access",
      icon: "fa-user-shield",
      href: "admin/team.html",
      perm: "team.view",
    },
    {
      label: "Roles & permissions",
      icon: "fa-key",
      href: "admin/roles.html",
      perm: "team.view",
    },
    {
      label: "Settings",
      icon: "fa-sliders",
      href: "admin/settings.html",
      perm: "settings.view",
    },
    {
      label: "Audit log",
      icon: "fa-list-check",
      href: "admin/audit.html",
      perm: "audit.view",
    },
  ],
};
