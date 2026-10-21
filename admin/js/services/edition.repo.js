import { store, uid } from "../../../shared/js/core/store.js";
import { getSeed } from "../../../shared/js/core/http.js";
import { collection } from "./admin-store.js";
import {
  listAssignments,
  saveAssignments,
  listAllAssignments,
} from "./team.repo.js";

const KEY = "admin:editions";
const VIEW = "admin:viewing";

export async function ensureEditions() {
  if (store.get(KEY) === null) {
    store.set(KEY, structuredClone(await getSeed("editions")));
  }
}

export const readEditions = () =>
  store.get(KEY) ?? { active: null, editions: [] };

const save = (doc) => store.set(KEY, doc);

export const listEditions = () =>
  [...readEditions().editions].sort((a, b) => (b.year ?? 0) - (a.year ?? 0));

export const getEdition = (id) =>
  readEditions().editions.find((e) => e.id === id) ?? null;

export const activeEditionId = () => readEditions().active;

export const isReadOnly = (edition) =>
  Boolean(edition) && ["completed", "archived"].includes(edition.status);

export const editionLabel = (edition) => `${edition.name} ${edition.edition}`;

export const getViewing = () => store.get(VIEW);

export function setViewing(id) {
  if (!getEdition(id)) return false;
  store.set(VIEW, id);
  return true;
}

export function updateEdition(id, mutate) {
  const doc = readEditions();
  const edition = doc.editions.find((e) => e.id === id);

  if (!edition) return null;

  mutate(edition);
  save(doc);

  return edition;
}

const ALLOWED = {
  draft: ["active"],
  active: ["completed"],
  completed: ["archived", "active"],
  archived: ["completed"],
};

export function transition(id, to) {
  const doc = readEditions();
  const edition = doc.editions.find((e) => e.id === id);

  if (!edition) {
    return { ok: false, error: "Edition not found." };
  }

  if (!(ALLOWED[edition.status] ?? []).includes(to)) {
    return {
      ok: false,
      error: `A ${edition.status} edition cannot become ${to}.`,
    };
  }

  if (to === "active") {
    const otherActive = doc.editions.find(
      (e) => e.id !== id && e.status === "active",
    );

    if (otherActive || (doc.active && doc.active !== id)) {
      const current =
        otherActive ?? doc.editions.find((e) => e.id === doc.active);

      return {
        ok: false,
        error: current
          ? `${editionLabel(current)} is still active. Complete it first. Only one edition can be active at a time.`
          : "Another edition is marked active. Resolve that edition first.",
      };
    }
  }

  edition.status = to;

  if (to === "active") {
    doc.active = id;
  } else if (doc.active === id) {
    doc.active = null;
  }

  save(doc);

  return { ok: true, edition };
}

const slug = (value) =>
  String(value)
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");

const defaultFeatures = () => ({
  tickets: false,
  committeeRecruitment: false,
  showVoteCounts: false,
});

const defaultVoting = () => ({
  pricePerVote: 100,
  maxVotesPerPayment: 50,
  maxPaidVotesPerEmailPerCategory: 5,
});

export async function createEdition({
  name,
  edition,
  year,
  tagline,
  copyFrom = null,
  copy = {},
}) {
  const doc = readEditions();
  const cleanName = String(name ?? "").trim();
  const cleanEdition = String(edition ?? "").trim();
  const numericYear = Number(year);

  if (!cleanName || !cleanEdition) {
    throw new Error("Enter an edition name and edition number.");
  }

  if (
    !Number.isInteger(numericYear) ||
    numericYear < 2000 ||
    numericYear > 2100
  ) {
    throw new Error("Enter a valid year between 2000 and 2100.");
  }

  const duplicate = doc.editions.some(
    (e) =>
      e.name.toLowerCase() === cleanName.toLowerCase() &&
      e.edition.toLowerCase() === cleanEdition.toLowerCase(),
  );

  if (duplicate) {
    throw new Error("That edition already exists.");
  }

  const src = copyFrom ? doc.editions.find((e) => e.id === copyFrom) : null;

  if (copyFrom && !src) {
    throw new Error("The source edition could not be found.");
  }

  let id = `${slug(cleanName)}-${slug(cleanEdition)}`;

  while (doc.editions.some((e) => e.id === id)) {
    id = `${slug(cleanName)}-${slug(cleanEdition)}-${uid("", 3).toLowerCase()}`;
  }

  const fresh = {
    id,
    name: cleanName,
    edition: cleanEdition,
    year: numericYear,
    status: "draft",
    tagline: String(tagline ?? "").trim() || src?.tagline || "",
    mode: "auto",
    forceState: null,

    timeline: copy.settings && src ? structuredClone(src.timeline ?? {}) : {},

    features:
      copy.settings && src
        ? structuredClone(src.features ?? defaultFeatures())
        : defaultFeatures(),

    voting:
      copy.settings && src
        ? structuredClone(src.voting ?? defaultVoting())
        : defaultVoting(),

    occasions:
      copy.occasions && src
        ? structuredClone(src.occasions ?? []).map((occasion) => ({
            ...occasion,
            enabled: false,
          }))
        : [],
  };

  doc.editions.push(fresh);
  save(doc);

  if (src && copy.categories) {
    const categories = collection("categories", {
      seed: "categories",
      prefix: "cat-",
    });

    const sourceCategories = (await categories.all()).filter(
      (item) => item.occasionId === src.id,
    );

    for (const category of sourceCategories) {
      const { id: oldId, createdAt, ...data } = category;

      await categories.create({
        ...data,
        occasionId: id,
      });
    }
  }

  if (src && copy.ticketTypes) {
    const ticketTypes = collection("ticket-types", {
      seed: "ticket-types",
      prefix: "TT-",
    });

    const sourceTypes = (await ticketTypes.all()).filter(
      (item) => item.occasionId === src.id,
    );

    for (const ticketType of sourceTypes) {
      const { id: oldId, createdAt, ...data } = ticketType;

      await ticketTypes.create({
        ...data,
        occasionId: id,
      });
    }
  }

  if (src && copy.team) {
    const existingAssignments = listAllAssignments();

    const copiedAssignments = listAssignments(src.id).map((assignment) => ({
      ...structuredClone(assignment),
      id: uid("ASG-"),
      editionId: id,
    }));

    saveAssignments([...existingAssignments, ...copiedAssignments]);
  }

  return fresh;
}

const recordBelongsToEdition = (record, id) =>
  record?.occasionId === id || record?.editionId === id;

function hasEditionActivity(id) {
  const keys = [
    "admin:nominees",
    "nominees",
    "admin:winners",
    "winners",
    "vote-orders",
    "tickets",
    "ticket-orders",
    "payments",
  ];

  return keys.some((key) =>
    (store.get(key, []) ?? []).some((record) =>
      recordBelongsToEdition(record, id),
    ),
  );
}

export function deleteDraftEdition(id) {
  const doc = readEditions();
  const edition = doc.editions.find((e) => e.id === id);

  if (!edition || edition.status !== "draft") {
    return {
      ok: false,
      error:
        "Only draft editions can be deleted. Archive completed editions instead.",
    };
  }

  if (doc.active === id) {
    return {
      ok: false,
      error: "The active edition cannot be deleted.",
    };
  }

  if (hasEditionActivity(id)) {
    return {
      ok: false,
      error:
        "This draft already has records. Keep its history and remove the records through the appropriate management pages instead.",
    };
  }

  doc.editions = doc.editions.filter((e) => e.id !== id);
  save(doc);

  saveAssignments(listAllAssignments().filter((a) => a.editionId !== id));

  for (const key of ["categories", "ticket-types"]) {
    const storageKey = `admin:${key}`;
    const rows = store.get(storageKey);

    if (rows) {
      store.set(
        storageKey,
        rows.filter((record) => record.occasionId !== id),
      );
    }
  }

  if (getViewing() === id) {
    store.remove(VIEW);
  }

  return { ok: true };
}
