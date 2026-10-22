import { store } from "../../../shared/js/core/store.js";
import { collection } from "./admin-store.js";
import { viewing, inEdition } from "./scope.js";

const seeds = collection("nominees", {
  seed: "nominees",
  prefix: "NOM-",
});

const LEGACY_KEY = "nominees";
const ENTRIES_KEY = "nominee-entries";
const ACCOUNTS_KEY = "nominee-accounts";

const legacyRegistrations = () => store.get(LEGACY_KEY, []);
const entries = () => store.get(ENTRIES_KEY, []);
const accounts = () => store.get(ACCOUNTS_KEY, []);

function accountFor(entry) {
  return accounts().find((account) => account.id === entry.accountId) ?? null;
}

function entryForAdmin(entry) {
  const account = accountFor(entry);

  return {
    ...entry,
    email: account?.email ?? entry.email ?? "",
    emailVerified: Boolean(account?.emailVerified),
    source: "registration",
  };
}

export async function listNominees(editionId = viewing()) {
  const [seeded] = await Promise.all([seeds.all()]);

  const oldRegistrations = legacyRegistrations()
    .filter((n) => inEdition(n, editionId))
    .map((n) => ({ ...n, source: "registration" }));

  const accountEntries = entries()
    .filter((n) => inEdition(n, editionId))
    .map(entryForAdmin);

  const base = seeded
    .filter((n) => inEdition(n, editionId))
    .map((n) => ({ ...n, source: "admin" }));

  const seen = new Set();
  const combined = [];

  for (const nominee of [...accountEntries, ...oldRegistrations, ...base]) {
    if (seen.has(nominee.id)) continue;
    seen.add(nominee.id);
    combined.push(nominee);
  }

  return combined;
}

export const pendingNomineeCount = (editionId = viewing()) => {
  const pending = new Set();

  for (const nominee of [...entries(), ...legacyRegistrations()]) {
    if (nominee.status !== "pending" || !inEdition(nominee, editionId)) {
      continue;
    }

    pending.add(String(nominee.legacyId || nominee.id));
  }

  return pending.size;
};

export async function setNomineeStatus(id, status) {
  const newEntries = entries();
  const entry = newEntries.find((n) => n.id === id);

  if (entry) {
    store.set(
      ENTRIES_KEY,
      newEntries.map((n) => (n.id === id ? { ...n, status } : n)),
    );
    return;
  }

  const legacy = legacyRegistrations();
  const old = legacy.find((n) => n.id === id);

  if (old) {
    store.set(
      LEGACY_KEY,
      legacy.map((n) => (n.id === id ? { ...n, status } : n)),
    );
    return;
  }

  await seeds.update(id, { status });
}

export async function saveNominee(id, data) {
  const newEntries = entries();
  const entry = newEntries.find((n) => n.id === id);

  if (entry) {
    const allowed = [
      "name",
      "phone",
      "department",
      "level",
      "categoryId",
      "bio",
      "status",
    ];

    const patch = {};
    for (const key of allowed) {
      if (Object.hasOwn(data, key)) patch[key] = data[key];
    }

    store.set(
      ENTRIES_KEY,
      newEntries.map((n) => (n.id === id ? { ...n, ...patch } : n)),
    );
    return;
  }

  const legacy = legacyRegistrations();
  const old = legacy.find((n) => n.id === id);

  if (old) {
    store.set(
      LEGACY_KEY,
      legacy.map((n) => (n.id === id ? { ...n, ...data } : n)),
    );
    return;
  }

  if (id) {
    await seeds.update(id, data);
  } else {
    await seeds.create({
      occasionId: viewing(),
      photo: null,
      ...data,
    });
  }
}

export async function removeNominee(id) {
  const newEntries = entries();

  if (newEntries.some((n) => n.id === id)) {
    store.set(
      ENTRIES_KEY,
      newEntries.filter((n) => n.id !== id),
    );
    return;
  }

  const legacy = legacyRegistrations();

  if (legacy.some((n) => n.id === id)) {
    store.set(
      LEGACY_KEY,
      legacy.filter((n) => n.id !== id),
    );
    return;
  }

  await seeds.remove(id);
}
