import { ADMIN } from "../config/admin.config.js";
import { store, delay, userError } from "../../../shared/js/core/store.js";
import { logAudit } from "./admin-store.js";

/**
 * DEMO admin auth (localStorage). Passwords are NOT checked here — the real backend will
 * authenticate securely. Keep these signatures when swapping in the API.
 */
const SESSION = "admin:session";
const ADMINS = "admin:admins";
export const SEED = [
  {
    id: "adm-1",
    name: "Super Admin",
    email: "admin@oga.local",
    role: "super_admin",
    active: true,
    seed: true,
  },
  {
    id: "adm-2",
    name: "Editor",
    email: "editor@oga.local",
    role: "editor",
    active: true,
    seed: true,
  },
  {
    id: "adm-3",
    name: "Gate Staff",
    email: "gate@oga.local",
    role: "gate",
    active: true,
    seed: true,
  },
];

export const DEMO_ACCOUNTS = SEED.map(({ email, role }) => ({ email, role }));

export function listAdmins() {
  const rows = store.get(ADMINS) ?? [];
  const missing = SEED.filter((s) => !rows.some((r) => r.id === s.id)); // demo accounts always exist
  if (missing.length || store.get(ADMINS) === null) {
    store.set(ADMINS, [...rows, ...missing]);
    return [...rows, ...missing];
  }
  return rows;
}
export const saveAdmins = (rows) => store.set(ADMINS, rows);

export async function loginAdmin({ email }) {
  await delay(500);
  const admin = listAdmins().find(
    (a) => a.email === email.trim().toLowerCase() && a.active,
  );
  if (!admin) throw userError("We could not sign you in with those details.");
  store.set(SESSION, admin.id);
  logAudit(admin, "Signed in");
  return admin;
}

export const currentAdmin = () =>
  listAdmins().find((a) => a.id === store.get(SESSION)) ?? null;
export function logoutAdmin() {
  logAudit(currentAdmin(), "Signed out");
  store.remove(SESSION);
}

export const roleLabel = (admin) =>
  ADMIN.roles[admin.role]?.label ?? admin.role;
export function can(admin, perm) {
  const perms = ADMIN.roles[admin?.role]?.perms ?? [];
  return perms.includes("*") || perms.includes(perm);
}
/** First menu page this admin may open (used after login). */
export const landingPage = (admin) =>
  ADMIN.menu.find((m) => m.href && !m.soon && can(admin, m.perm))?.href ?? null;
