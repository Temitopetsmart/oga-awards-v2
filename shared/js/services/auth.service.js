import { store, delay, uid, userError } from "../core/store.js";
import { getHomeContext } from "./event.service.js";

const ACCOUNTS = "nominee-accounts";
const ENTRIES = "nominee-entries";
const LEGACY = "nominees";
const SESSION = "nominee-session";

const normalizeEmail = (email) =>
  String(email ?? "")
    .trim()
    .toLowerCase();

function readAccounts() {
  return store.get(ACCOUNTS, []);
}

function readEntries() {
  return store.get(ENTRIES, []);
}

function migrateLegacyData() {
  const accounts = readAccounts();
  const entries = readEntries();
  const legacy = store.get(LEGACY, []);

  if (!legacy.length) return;

  const nextAccounts = [...accounts];
  const nextEntries = [...entries];

  for (const old of legacy) {
    const email = normalizeEmail(old.email);
    if (!email) continue;

    let account = nextAccounts.find((item) => item.email === email);

    if (!account) {
      account = {
        id: uid("ACC-", 8),
        email,
        emailVerified: false,
        passwordHash: null,
        createdAt: old.createdAt ?? new Date().toISOString(),
        migrated: true,
      };
      nextAccounts.push(account);
    }

    const alreadyExists = nextEntries.some(
      (entry) =>
        entry.legacyId === old.id ||
        (entry.accountId === account.id && entry.occasionId === old.occasionId),
    );

    if (!alreadyExists) {
      const { id: oldId, email: oldEmail, occasionId, ...profile } = old;

      nextEntries.push({
        ...profile,
        id: uid("ENTRY-", 8),
        legacyId: oldId,
        accountId: account.id,
        occasionId,
        email: account.email,
        status: old.status ?? "pending",
        createdAt: old.createdAt ?? new Date().toISOString(),
      });
    }
  }

  store.set(ACCOUNTS, nextAccounts);
  store.set(ENTRIES, nextEntries);
}

function ensureMigrated() {
  migrateLegacyData();
}

async function hashPassword(password) {
  if (!globalThis.crypto?.subtle) {
    throw userError(
      "Secure password hashing is unavailable in this browser. Please use an updated browser.",
    );
  }

  const bytes = new TextEncoder().encode(password);
  const digest = await crypto.subtle.digest("SHA-256", bytes);

  return [...new Uint8Array(digest)]
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
}

function getSession() {
  return store.get(SESSION, null);
}

function getAccountById(id) {
  return readAccounts().find((account) => account.id === id) ?? null;
}

function getEntryById(id) {
  return readEntries().find((entry) => entry.id === id) ?? null;
}

function toNominee(account, entry) {
  if (!account || !entry) return null;

  return {
    ...entry,
    accountId: account.id,
    email: account.email,
    emailVerified: Boolean(account.emailVerified),
    entries: readEntries()
      .filter((item) => item.accountId === account.id)
      .map((item) => ({ ...item })),
  };
}

function currentEntry() {
  ensureMigrated();

  const session = getSession();
  if (!session) return null;

  const account = getAccountById(session.accountId);
  const entry =
    getEntryById(session.entryId) ??
    readEntries().find((item) => item.accountId === account?.id);

  return toNominee(account, entry);
}

export async function registerNominee(data) {
  await delay(300);
  ensureMigrated();

  const context = await getHomeContext();

  if (context.state !== "nominations") {
    throw userError(
      "Nominations are currently closed. You cannot register at this time.",
    );
  }

  if (data.occasionId !== context.occasion.id) {
    throw userError(
      "This registration belongs to a different edition. Refresh the page and try again.",
    );
  }

  const email = normalizeEmail(data.email);
  const password = String(data.password ?? "");

  if (password.length < 8) {
    throw userError("Your password must contain at least 8 characters.", {
      password: "Use at least 8 characters.",
    });
  }

  if (!email) {
    throw userError("Enter a valid email address.", {
      email: "Email is required.",
    });
  }

  const accounts = readAccounts();
  const entries = readEntries();

  if (accounts.some((account) => account.email === email)) {
    throw userError(
      "An account with this email already exists. Log in to continue.",
      { email: "Already registered — try logging in." },
    );
  }

  if (
    entries.some(
      (entry) => entry.occasionId === data.occasionId && entry.email === email,
    )
  ) {
    throw userError("An entry already exists for this edition and email.");
  }

  const passwordHash = await hashPassword(password);

  const account = {
    id: uid("ACC-", 8),
    email,
    passwordHash,
    emailVerified: false,
    createdAt: new Date().toISOString(),
  };

  const entry = {
    id: uid("ENTRY-", 8),
    accountId: account.id,
    occasionId: data.occasionId,
    name: String(data.name ?? "").trim(),
    phone: String(data.phone ?? "").trim(),
    department: String(data.department ?? "").trim(),
    level: String(data.level ?? "").trim(),
    categoryId: data.categoryId,
    bio: "",
    status: "pending",
    createdAt: new Date().toISOString(),
  };

  store.set(ACCOUNTS, [...accounts, account]);
  store.set(ENTRIES, [...entries, entry]);
  store.set(SESSION, {
    accountId: account.id,
    entryId: entry.id,
  });

  return toNominee(account, entry);
}

export async function loginNominee({ email, password }) {
  await delay(300);
  ensureMigrated();

  const normalized = normalizeEmail(email);
  const account = readAccounts().find((item) => item.email === normalized);

  if (!account) {
    throw userError(
      "We could not find an account with those details. Check your email or register.",
    );
  }

  if (!account.passwordHash) {
    throw userError(
      "This is an older demo account. Password recovery or account setup must be completed before login.",
    );
  }

  const passwordHash = await hashPassword(String(password ?? ""));

  if (passwordHash !== account.passwordHash) {
    throw userError("The email or password is incorrect.");
  }

  const entries = readEntries().filter(
    (entry) => entry.accountId === account.id,
  );

  if (!entries.length) {
    throw userError(
      "Your account has no nominee entry yet. Start a registration for an open edition.",
    );
  }

  const session = getSession();
  const selectedEntry =
    entries.find((entry) => entry.id === session?.entryId) ?? entries[0];

  store.set(SESSION, {
    accountId: account.id,
    entryId: selectedEntry.id,
  });

  return toNominee(account, selectedEntry);
}

export const currentNominee = () => currentEntry();

export function logoutNominee() {
  store.remove(SESSION);
}

export async function updateNominee(patch) {
  await delay(250);
  ensureMigrated();

  const session = getSession();
  if (!session) {
    throw userError("Your session has expired. Please log in again.");
  }

  const entries = readEntries();
  const index = entries.findIndex(
    (entry) =>
      entry.id === session.entryId && entry.accountId === session.accountId,
  );

  if (index < 0) {
    throw userError("We could not find your nominee entry.");
  }

  const allowed = ["name", "phone", "department", "level", "bio"];
  const safePatch = {};

  for (const key of allowed) {
    if (Object.hasOwn(patch, key)) safePatch[key] = patch[key];
  }

  entries[index] = { ...entries[index], ...safePatch };
  store.set(ENTRIES, entries);

  return currentEntry();
}

export function listMyEntries() {
  ensureMigrated();

  const session = getSession();
  if (!session) return [];

  return readEntries()
    .filter((entry) => entry.accountId === session.accountId)
    .map((entry) => ({ ...entry }));
}

export function switchNomineeEntry(entryId) {
  ensureMigrated();

  const session = getSession();
  if (!session) {
    throw userError("Please log in to continue.");
  }

  const entry = readEntries().find(
    (item) => item.id === entryId && item.accountId === session.accountId,
  );

  if (!entry) {
    throw userError("That entry does not belong to your account.");
  }

  store.set(SESSION, {
    accountId: session.accountId,
    entryId: entry.id,
  });

  return currentEntry();
}

export function findNomineeById(id) {
  ensureMigrated();

  const entry = getEntryById(id);
  if (entry) {
    return toNominee(getAccountById(entry.accountId), entry);
  }

  const legacy = store.get(LEGACY, []).find((item) => item.id === id);

  if (legacy) return legacy;

  return store.get("admin:nominees", []).find((item) => item.id === id) ?? null;
}

export async function requestPasswordReset(email) {
  await delay(250);
  ensureMigrated();

  const normalized = normalizeEmail(email);
  const account = readAccounts().find((item) => item.email === normalized);

  if (!account) {
    throw userError("No account was found for that email address.", {
      email: "No account found.",
    });
  }

  const token = uid("RESET-", 12);
  const resets = store.get("nominee-password-resets", []);
  const now = Date.now();

  store.set("nominee-password-resets", [
    ...resets.filter((item) => item.expiresAt > now),
    {
      accountId: account.id,
      token,
      expiresAt: now + 30 * 60 * 1000,
      used: false,
    },
  ]);

  return {
    token,
    expiresInMinutes: 30,
    demoOnly: true,
  };
}

export async function resetNomineePassword({ email, token, password }) {
  await delay(250);
  ensureMigrated();

  if (String(password ?? "").length < 8) {
    throw userError("Use a password with at least 8 characters.", {
      password: "Use at least 8 characters.",
    });
  }

  const normalized = normalizeEmail(email);
  const accounts = readAccounts();
  const account = accounts.find((item) => item.email === normalized);

  if (!account) {
    throw userError("The reset details are invalid or expired.");
  }

  const resets = store.get("nominee-password-resets", []);
  const reset = resets.find(
    (item) =>
      item.accountId === account.id &&
      item.token === String(token ?? "").trim() &&
      !item.used &&
      item.expiresAt > Date.now(),
  );

  if (!reset) {
    throw userError("The reset token is invalid or has expired.");
  }

  const passwordHash = await hashPassword(password);

  store.set(
    ACCOUNTS,
    accounts.map((item) =>
      item.id === account.id ? { ...item, passwordHash } : item,
    ),
  );

  store.set(
    "nominee-password-resets",
    resets.map((item) => (item === reset ? { ...item, used: true } : item)),
  );

  return { success: true };
}

export async function changeNomineePassword({ currentPassword, newPassword }) {
  await delay(250);

  const session = getSession();
  if (!session) {
    throw userError("Please log in again before changing your password.");
  }

  if (String(newPassword ?? "").length < 8) {
    throw userError("Use a password with at least 8 characters.", {
      newPassword: "Use at least 8 characters.",
    });
  }

  const accounts = readAccounts();
  const account = accounts.find((item) => item.id === session.accountId);

  if (!account) {
    throw userError("Your account could not be found.");
  }

  if (account.passwordHash) {
    const currentHash = await hashPassword(currentPassword ?? "");

    if (currentHash !== account.passwordHash) {
      throw userError("Your current password is incorrect.", {
        currentPassword: "Password does not match.",
      });
    }
  }

  const passwordHash = await hashPassword(newPassword);

  store.set(
    ACCOUNTS,
    accounts.map((item) =>
      item.id === account.id ? { ...item, passwordHash } : item,
    ),
  );

  return { success: true };
}

export async function createNomineeEntry({ occasionId, categoryId }) {
  await delay(250);
  ensureMigrated();

  const context = await getHomeContext();

  if (context.state !== "nominations") {
    throw userError("Nominations are not open for the current edition.");
  }

  if (occasionId !== context.occasion.id) {
    throw userError(
      "The selected edition is no longer active. Refresh the page and try again.",
    );
  }

  const session = getSession();

  if (!session) {
    throw userError("Please log in before applying for another edition.");
  }

  const account = getAccountById(session.accountId);

  if (!account) {
    throw userError("Your account could not be found.");
  }

  const entries = readEntries();

  if (
    entries.some(
      (entry) =>
        entry.accountId === account.id && entry.occasionId === occasionId,
    )
  ) {
    throw userError("You already have an entry for this edition.");
  }

  const previous = getEntryById(session.entryId);

  if (!previous || previous.accountId !== account.id) {
    throw userError("We could not find your existing nominee profile.");
  }

  if (!categoryId) {
    throw userError("Choose a category before applying.");
  }

  const entry = {
    id: uid("ENTRY-", 8),
    accountId: account.id,
    occasionId,
    name: previous.name,
    phone: previous.phone ?? "",
    department: previous.department ?? "",
    level: previous.level ?? "",
    categoryId,
    bio: previous.bio ?? "",
    status: "pending",
    createdAt: new Date().toISOString(),
  };

  store.set(ENTRIES, [...entries, entry]);
  store.set(SESSION, {
    accountId: account.id,
    entryId: entry.id,
  });

  return toNominee(account, entry);
}

export async function requestEmailVerification() {
  await delay(200);
  ensureMigrated();

  const session = getSession();
  if (!session) throw userError("Please log in first.");

  const account = getAccountById(session.accountId);
  if (!account) throw userError("Your account could not be found.");

  if (account.emailVerified) {
    return { alreadyVerified: true };
  }

  const token = uid("VERIFY-", 12);
  const verifications = store.get("nominee-email-verifications", []);
  const now = Date.now();

  store.set("nominee-email-verifications", [
    ...verifications.filter((item) => item.expiresAt > now && !item.used),
    {
      accountId: account.id,
      token,
      expiresAt: now + 30 * 60 * 1000,
      used: false,
    },
  ]);

  return { token, expiresInMinutes: 30, demoOnly: true };
}

export async function verifyNomineeEmail(token) {
  await delay(200);
  ensureMigrated();

  const session = getSession();
  if (!session) throw userError("Please log in before verifying your email.");

  const verifications = store.get("nominee-email-verifications", []);
  const verification = verifications.find(
    (item) =>
      item.accountId === session.accountId &&
      item.token === String(token ?? "").trim() &&
      !item.used &&
      item.expiresAt > Date.now(),
  );

  if (!verification) {
    throw userError("The verification token is invalid or has expired.");
  }

  store.set(
    "nominee-accounts",
    readAccounts().map((account) =>
      account.id === session.accountId
        ? { ...account, emailVerified: true }
        : account,
    ),
  );

  store.set(
    "nominee-email-verifications",
    verifications.map((item) =>
      item === verification ? { ...item, used: true } : item,
    ),
  );

  return { success: true };
}
