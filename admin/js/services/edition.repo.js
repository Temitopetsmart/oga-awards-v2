import { store, uid } from "../../../shared/js/core/store.js";
import { getSeed } from "../../../shared/js/core/http.js";
import { collection } from "./admin-store.js";
import {
  listAssignments,
  saveAssignments,
  listAllAssignments,
} from "./team.repo.js";

/**
 * Editions (OGA 10.0, 11.0 …). One document holds the list and which edition is active.
 * DEMO store; the backend will keep these in an `editions` table. Keep the function names.
 */
const KEY = "admin:editions";
const VIEW = "admin:viewing";

export async function ensureEditions() {
  if (store.get(KEY) === null)
    store.set(KEY, structuredClone(await getSeed("editions")));
}
export const readEditions = () =>
  store.get(KEY) ?? { active: null, editions: [] };
const save = (doc) => store.set(KEY, doc);
export const listEditions = () =>
  [...readEditions().editions].sort((a, b) => (b.year ?? 0) - (a.year ?? 0));
export const getEdition = (id) =>
  readEditions().editions.find((e) => e.id === id) ?? null;
export const activeEditionId = () => readEditions().active;
export const isReadOnly = (e) =>
  Boolean(e) && (e.status === "completed" || e.status === "archived");
export const editionLabel = (e) => `${e.name} ${e.edition}`;

export const getViewing = () => store.get(VIEW);
export const setViewing = (id) => store.set(VIEW, id);

/** Edit an edition's fields (name, number, year, tagline, timeline, features, voting, occasions…). */
export function updateEdition(id, mutate) {
  const doc = readEditions();
  const e = doc.editions.find((x) => x.id === id);
  if (!e) return null;
  mutate(e);
  save(doc);
  return e;
}

const ALLOWED = {
  draft: ["active"],
  active: ["completed"],
  completed: ["archived", "active"],
  archived: ["completed"],
};

/** Move an edition through its life: draft → active → completed → archived (and back one step). */
export function transition(id, to) {
  const doc = readEditions();
  const e = doc.editions.find((x) => x.id === id);
  if (!e) return { ok: false, error: "Edition not found." };
  if (!(ALLOWED[e.status] ?? []).includes(to))
    return { ok: false, error: `A ${e.status} edition can’t become ${to}.` };
  if (to === "active" && doc.active && doc.active !== id) {
    const cur = doc.editions.find((x) => x.id === doc.active);
    return {
      ok: false,
      error: `${editionLabel(cur)} is still active. Complete it first — only one edition can be active at a time.`,
    };
  }
  e.status = to;
  if (to === "active") doc.active = id;
  if (doc.active === id && to !== "active") doc.active = null;
  save(doc);
  return { ok: true, edition: e };
}

const slug = (s) =>
  String(s)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");

/**
 * Create a new draft edition. Nominees, votes, tickets, payments and results are never copied.
 * copy: { categories, ticketTypes, settings, occasions, team } (all optional)
 */
export async function createEdition({
  name,
  edition,
  year,
  tagline,
  copyFrom = null,
  copy = {},
}) {
  const doc = readEditions();
  let id = `${slug(name)}-${slug(edition)}`;
  if (doc.editions.some((e) => e.id === id))
    id += `-${uid("", 3).toLowerCase()}`;
  const src = copyFrom ? doc.editions.find((e) => e.id === copyFrom) : null;
  const fresh = {
    id,
    name,
    edition,
    year: Number(year),
    status: "draft",
    tagline: tagline || src?.tagline || "",
    mode: "auto",
    forceState: null,
    timeline: {},
    features:
      copy.settings && src
        ? structuredClone(src.features)
        : {
            tickets: false,
            committeeRecruitment: false,
            showVoteCounts: false,
          },
    voting:
      copy.settings && src
        ? structuredClone(src.voting)
        : {
            pricePerVote: 100,
            maxVotesPerPayment: 50,
            maxPaidVotesPerEmailPerCategory: 5,
          },
    occasions:
      copy.occasions && src
        ? structuredClone(src.occasions ?? []).map((o) => ({
            ...o,
            enabled: false,
          }))
        : [],
  };
  doc.editions.push(fresh);
  save(doc);

  if (src && copy.categories) {
    const cats = collection("categories", {
      seed: "categories",
      prefix: "cat-",
    });
    for (const c of (await cats.all()).filter((x) => x.occasionId === src.id))
      await cats.create({ ...c, id: undefined, occasionId: id });
  }
  if (src && copy.ticketTypes) {
    const types = collection("ticket-types", {
      seed: "ticket-types",
      prefix: "TT-",
    });
    for (const t of (await types.all()).filter((x) => x.occasionId === src.id))
      await types.create({ ...t, id: undefined, occasionId: id });
  }
  if (src && copy.team) {
    saveAssignments([
      ...listAllAssignments(),
      ...listAssignments(src.id).map((a) => ({
        ...a,
        id: uid("ASG-"),
        editionId: id,
      })),
    ]);
  }
  return fresh;
}

/** Only draft editions with no activity can be deleted. */
export function deleteDraftEdition(id) {
  const doc = readEditions();
  const e = doc.editions.find((x) => x.id === id);
  if (!e || e.status !== "draft")
    return {
      ok: false,
      error:
        "Only draft editions can be deleted. Archive completed editions instead.",
    };
  doc.editions = doc.editions.filter((x) => x.id !== id);
  save(doc);
  saveAssignments(listAllAssignments().filter((a) => a.editionId !== id));
  for (const key of ["categories", "ticket-types"]) {
    const rows = store.get(`admin:${key}`);
    if (rows)
      store.set(
        `admin:${key}`,
        rows.filter((r) => r.occasionId !== id),
      );
  }
  return { ok: true };
}
