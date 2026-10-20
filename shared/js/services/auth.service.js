import { store, delay, uid, userError } from "../core/store.js";

/**
 * DEMO nominee auth (localStorage). Passwords are NOT stored or checked here — the real
 * backend will handle authentication securely. Keep these signatures when swapping in the API.
 */
const USERS = "nominees";
const SESSION = "nominee-session";

export async function registerNominee(data) {
  await delay(500);
  const users = store.get(USERS, []);
  const email = data.email.trim().toLowerCase();
  if (users.some((u) => u.email === email))
    throw userError("An account with this email already exists.", {
      email: "Already registered — try logging in.",
    });
  const user = {
    id: uid("NOM-", 6),
    occasionId: data.occasionId,
    name: data.name.trim(),
    email,
    phone: data.phone,
    department: data.department,
    level: data.level,
    categoryId: data.categoryId,
    bio: "",
    status: "pending",
    createdAt: new Date().toISOString(),
  };
  store.set(USERS, [...users, user]);
  store.set(SESSION, user.id);
  return user;
}

export async function loginNominee({ email }) {
  await delay(500);
  const user = store
    .get(USERS, [])
    .find((u) => u.email === email.trim().toLowerCase());
  if (!user)
    throw userError("We could not find an account with those details.");
  store.set(SESSION, user.id);
  return user;
}

export const currentNominee = () =>
  store.get(USERS, []).find((u) => u.id === store.get(SESSION)) ?? null;
export const logoutNominee = () => store.remove(SESSION);

export async function updateNominee(patch) {
  await delay(400);
  const users = store.get(USERS, []);
  const user = users.find((u) => u.id === store.get(SESSION));
  if (!user) throw userError("Your session has expired. Please log in again.");
  Object.assign(user, patch);
  store.set(USERS, users);
  return user;
}

/** DEMO BRIDGE: find a nominee by id in either demo store (used by the admin's "view as nominee"). */
export function findNomineeById(id) {
  return (
    store.get(USERS, []).find((u) => u.id === id) ??
    (store.get("admin:nominees", []) ?? []).find((u) => u.id === id) ??
    null
  );
}
