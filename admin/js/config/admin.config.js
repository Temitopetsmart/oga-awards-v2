/**
 * Admin configuration: menu, roles and permissions.
 * `soon: true` shows a menu item as disabled until its page exists.
 */
export const ADMIN = {
  title: "OGA Admin",

  /** Role -> permissions. '*' means everything. A page declares the permission it needs. */
  roles: {
    super_admin: { label: "Super admin", perms: ["*"] },
    editor: {
      label: "Editor",
      perms: [
        "dashboard",
        "nominees",
        "categories",
        "votes",
        "results",
        "committee",
        "messages",
        "occasions",
      ],
    },
    gate: { label: "Gate staff", perms: ["scanner"] },
  },

  menu: [
    { section: "Overview" },
    {
      label: "Dashboard",
      icon: "fa-gauge-high",
      href: "admin/index.html",
      perm: "dashboard",
    },
    { section: "Awards" },
    {
      label: "Nominees",
      icon: "fa-user-group",
      href: "admin/nominees.html",
      perm: "nominees",
      badge: "pendingNominees",
    },
    {
      label: "Categories",
      icon: "fa-layer-group",
      href: "admin/categories.html",
      perm: "categories",
    },
    {
      label: "Votes",
      icon: "fa-check-to-slot",
      href: "admin/votes.html",
      perm: "votes",
    },
    {
      label: "Results",
      icon: "fa-trophy",
      href: "admin/results.html",
      perm: "results",
    },
    { section: "Events" },
    {
      label: "Tickets",
      icon: "fa-ticket",
      href: "admin/tickets.html",
      perm: "tickets",
    },
    {
      label: "Gate scanner",
      icon: "fa-qrcode",
      href: "admin/scanner.html",
      perm: "scanner",
    },
    {
      label: "Occasions",
      icon: "fa-crown",
      href: "admin/occasions.html",
      perm: "occasions",
    },
    { section: "People" },
    {
      label: "Committee",
      icon: "fa-people-group",
      href: "admin/committee.html",
      perm: "committee",
      badge: "newApplications",
    },
    {
      label: "Messages",
      icon: "fa-envelope",
      href: "admin/messages.html",
      perm: "messages",
      badge: "unreadMessages",
    },
    { section: "System" },
    {
      label: "Settings",
      icon: "fa-sliders",
      href: "admin/settings.html",
      perm: "settings",
    },
    {
      label: "Admins & roles",
      icon: "fa-user-shield",
      href: "admin/admins.html",
      perm: "admins",
    },
    {
      label: "Audit log",
      icon: "fa-clock-rotate-left",
      href: "admin/audit.html",
      perm: "audit",
    },
  ],
};
