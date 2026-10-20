import { store, delay, userError } from "../../../shared/js/core/store.js";
import { ADMIN } from "../config/admin.config.js";
import { logAudit } from "./admin-store.js";
import { ensureTeam, listPeople, getPerson } from "./team.repo.js";
import { ensureEditions, listEditions } from "./edition.repo.js";
import { accessibleEditionIds } from "./team.repo.js";
import { buildAccess } from "../core/access.js";

/**
 * DEMO admin auth (localStorage). Passwords are NOT checked here — the real backend will
 * authenticate securely. Keep these signatures when swapping in the API.
 */
const SESSION = "admin:session";
const VIEW_AS = "admin:viewas";

export const DEMO_ACCOUNTS = [
  { email: "admin@oga.local", label: "Super admin" },
  { email: "editor@oga.local", label: "Editor" },
  { email: "treasurer@oga.local", label: "Treasurer" },
  { email: "secretary@oga.local", label: "Secretary" },
  { email: "publicity@oga.local", label: "Publicity" },
  { email: "gate@oga.local", label: "Gate staff" },
];

export async function seedDemo() {
  await ensureEditions();
  await ensureTeam();
}

export async function loginAdmin({ email }) {
  await delay(500);
  await seedDemo();
  const person = listPeople().find(
    (p) => p.email && p.email === email.trim().toLowerCase() && p.active,
  );
  if (!person || accessibleEditionIds(person, listEditions()).length === 0)
    throw userError("We could not sign you in with those details.");
  store.set(SESSION, person.id);
  store.remove(VIEW_AS);
  logAudit(person, "Signed in", "", null);
  return person;
}

export const currentAdmin = () => {
  const p = getPerson(store.get(SESSION));
  return p?.active ? p : null;
};
export function logoutAdmin() {
  logAudit(currentAdmin(), "Signed out", "", null);
  store.remove(SESSION);
  store.remove(VIEW_AS);
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
