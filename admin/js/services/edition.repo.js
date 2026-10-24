import { store } from "../../../shared/js/core/store.js";
import { getSeed } from "../../../shared/js/core/http.js";

const KEY = "admin:editions";
const VIEW = "admin:viewing";

export async function ensureEditions() {
  if (store.get(KEY) === null) {
    store.set(KEY, structuredClone(await getSeed("editions")));
  }
}

export const readEditions = () =>
  store.get(KEY) ?? { active: null, editions: [] };

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
