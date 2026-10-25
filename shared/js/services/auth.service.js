import { store, userError } from "../core/store.js";
import { APP } from "../config/app.config.js";

/**
 * Nominee accounts, served by the PHP backend. The server holds the real sign-in (a cookie JavaScript
 * cannot read); this file keeps a copy of who you are and your entries in the browser so pages can
 * read it instantly. The copy is refreshed from the server whenever a page loads.
 */
const CACHE = "nominee-session"; // { account, entries }
const SELECTED = "nominee-entry"; // which of my entries this browser is showing

let csrf = null;

async function send(
  path,
  { method = "GET", body, form, keepalive = false } = {},
) {
  const headers = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (method !== "GET" && csrf) headers["X-CSRF-Token"] = csrf;
  const res = await fetch(`${APP.data.apiBase}${path}`, {
    method,
    headers,
    credentials: "same-origin",
    body: form ?? (body === undefined ? undefined : JSON.stringify(body)),
    keepalive,
  });
  let data = null;
  try {
    data = await res.json();
  } catch {
    /* not JSON */
  }
  return { res, data };
}

async function refreshCsrf() {
  const { data } = await send("/nominee/csrf");
  csrf = data?.csrf ?? null;
}

/** Calls /nominee/… ; returns the JSON or throws an error that is safe to show (with per-field messages). */
async function call(path, options = {}) {
  if (options.method && options.method !== "GET" && !csrf) await refreshCsrf();
  let { res, data } = await send(path, options);
  if (res.status === 419) {
    await refreshCsrf();
    ({ res, data } = await send(path, options));
  }
  if (data?.csrf) csrf = data.csrf;
  if (!res.ok || data?.ok === false)
    throw userError(
      data?.error ?? "Something went wrong. Please try again.",
      data?.fields,
    );
  return data;
}

function hydrate(data) {
  csrf = data.csrf ?? csrf;
  store.set(CACHE, { account: data.account, entries: data.entries });
  const picked = store.get(SELECTED);
  if (!data.entries.some((e) => e.id === picked)) {
    const first = data.entries.at(-1)?.id; // newest entry by default
    if (first) store.set(SELECTED, first);
  }
}

const clear = () => {
  store.remove(CACHE);
  store.remove(SELECTED);
};

/** Ask the server who is signed in. Runs once when any page that uses nominee accounts loads. */
export async function restoreNomineeSession() {
  try {
    const data = await call("/nominee/me");
    if (data.authenticated) hydrate(data);
    else clear();
  } catch {
    clear(); // server unreachable: treat as signed out
  }
}
await restoreNomineeSession();

function build(entry) {
  const cache = store.get(CACHE);
  if (!cache || !entry) return null;
  return {
    ...entry,
    accountId: cache.account.id,
    email: cache.account.email,
    emailVerified: Boolean(cache.account.emailVerified),
    entries: cache.entries.map((e) => ({ ...e })),
  };
}

function selectedEntry() {
  const cache = store.get(CACHE);
  if (!cache) return null;
  const id = store.get(SELECTED);
  return cache.entries.find((e) => e.id === id) ?? cache.entries.at(-1) ?? null;
}

export const currentNominee = () => build(selectedEntry());

export async function registerNominee(data) {
  const r = await call("/nominee/register", { method: "POST", body: data });
  hydrate(r);
  store.set(SELECTED, r.entries.at(-1).id);
  return currentNominee();
}

export async function loginNominee({ email, password }) {
  const r = await call("/nominee/login", {
    method: "POST",
    body: { email, password },
  });
  hydrate(r);
  return currentNominee();
}

export function logoutNominee() {
  clear();
  return send("/nominee/logout", { method: "POST", keepalive: true }).catch(
    () => {},
  );
}

/** Saves the profile fields of the entry being shown. (Photos are uploaded with uploadNomineePhoto.) */
export async function updateNominee(patch) {
  const me = currentNominee();
  if (!me) throw userError("Your session has expired. Please log in again.");
  const next = { ...me, ...patch };
  const r = await call(`/nominee/entries/${me.id}`, {
    method: "PATCH",
    body: {
      name: next.name,
      phone: next.phone,
      department: next.department,
      level: next.level,
      bio: next.bio ?? "",
    },
  });
  hydrate(r);
  return currentNominee();
}

/** file: a File from an <input type="file">. The server checks it and makes the sizes. */
export async function uploadNomineePhoto(file) {
  const me = currentNominee();
  if (!me) throw userError("Your session has expired. Please log in again.");
  const form = new FormData();
  form.append("photo", file);
  const r = await call(`/nominee/entries/${me.id}/photo`, {
    method: "POST",
    form,
  });
  hydrate(r);
  return currentNominee();
}

export function listMyEntries() {
  return (store.get(CACHE)?.entries ?? []).map((e) => ({ ...e }));
}

export function switchNomineeEntry(entryId) {
  const cache = store.get(CACHE);
  if (!cache) throw userError("Please log in to continue.");
  const entry = cache.entries.find((e) => e.id === entryId);
  if (!entry) throw userError("That entry does not belong to your account.");
  store.set(SELECTED, entry.id);
  return currentNominee();
}

/** For the admin's "View as nominee" preview. The admin's own sign-in decides whether the server answers. */
export async function findNomineeById(id) {
  const { res, data } = await send(`/admin/nominees/${encodeURIComponent(id)}`);
  return res.ok ? data.nominee : null;
}

export async function requestPasswordReset(email) {
  const r = await call("/nominee/password/forgot", {
    method: "POST",
    body: { email },
  });
  return {
    token: r.devToken ?? "", // only present on a local development server; in production it is emailed
    expiresInMinutes: r.expiresInMinutes,
    demoOnly: Boolean(r.devToken),
  };
}

export async function resetNomineePassword({ email, token, password }) {
  return call("/nominee/password/reset", {
    method: "POST",
    body: { email, token, password },
  });
}

export async function changeNomineePassword({ currentPassword, newPassword }) {
  return call("/nominee/password/change", {
    method: "POST",
    body: { currentPassword, newPassword },
  });
}

export async function createNomineeEntry({ occasionId, categoryId }) {
  const r = await call("/nominee/entries", {
    method: "POST",
    body: { occasionId, categoryId },
  });
  hydrate(r);
  store.set(SELECTED, r.entryId);
  return currentNominee();
}

export async function requestEmailVerification() {
  const r = await call("/nominee/email/send", { method: "POST", body: {} });
  if (r.alreadyVerified) return { alreadyVerified: true };
  return {
    token: r.devToken ?? "",
    expiresInMinutes: r.expiresInMinutes,
    demoOnly: Boolean(r.devToken),
  };
}

export async function verifyNomineeEmail(token) {
  const r = await call("/nominee/email/verify", {
    method: "POST",
    body: { token },
  });
  await restoreNomineeSession(); // pick up "verified"
  return r;
}
