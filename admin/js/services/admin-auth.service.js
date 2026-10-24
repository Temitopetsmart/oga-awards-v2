import { store } from "../../../shared/js/core/store.js";
import { ADMIN } from "../config/admin.config.js";
import { adminApi, setCsrf } from "./admin-api.js";
import { listEditions } from "./edition.repo.js";
import { accessibleEditionIds } from "./team.repo.js";
import { buildAccess } from "../core/access.js";

/**
 * Admin sign-in against the PHP backend. The PHP session cookie is the real login; this file
 * keeps a copy of who you are and what you may see in the browser so pages can render
 * instantly. The server checks permissions again on every request.
 */
const ME = "admin:me";
const VIEW_AS = "admin:viewas";
const SESSION_KEYS = [
  ME,
  "admin:people",
  "admin:roles",
  "admin:assignments",
  "admin:editions",
  VIEW_AS,
  "admin:viewing",
];

function hydrate(data) {
  setCsrf(data.csrf);
  store.set(ME, data.person);
  store.set("admin:people", data.people);
  store.set("admin:roles", data.roles);
  store.set("admin:assignments", data.assignments);
  store.set("admin:editions", data.editions);
}

const clearSession = () => SESSION_KEYS.forEach((k) => store.remove(k));

/** Ask the server who is signed in. Call once when an admin page starts. */
export async function restoreSession() {
  try {
    const data = await adminApi("/admin/me");
    if (data.authenticated) hydrate(data);
    else clearSession();
  } catch {
    clearSession(); // API unreachable: treat as signed out
  }
}

export async function loginAdmin({ email, password }) {
  const data = await adminApi("/admin/login", {
    method: "POST",
    body: { email: email.trim().toLowerCase(), password },
  });
  store.remove(VIEW_AS);
  hydrate(data);
  return currentAdmin();
}

export const currentAdmin = () => {
  const p = store.get(ME);
  return p?.active ? p : null;
};

/** Clears the browser copy at once and signs out on the server (keepalive lets it finish while the page changes). */
export function logoutAdmin() {
  clearSession();
  return adminApi("/admin/logout", { method: "POST", keepalive: true }).catch(
    () => {},
  );
}

export const getViewAs = () => store.get(VIEW_AS);
export const setViewAs = (target) => store.set(VIEW_AS, target);
export const clearViewAs = () => store.remove(VIEW_AS);

/** First menu page the person may open in an edition (used right after login). */
export function landingPage(person) {
  const editions = listEditions();
  const ids = accessibleEditionIds(person, editions);
  const active =
    editions.find((e) => e.status === "active" && ids.includes(e.id)) ??
    editions.find((e) => ids.includes(e.id));
  if (!active) return null;
  store.set("admin:viewing", active.id);
  const access = buildAccess(person, active, null);
  return ADMIN.menu.find((m) => m.href && access.can(m.perm))?.href ?? null;
}
