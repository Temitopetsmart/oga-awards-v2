import { store } from "../../../shared/js/core/store.js";

/**
 * Edition scoping. Every record belongs to an edition (occasionId / editionId).
 */
export const viewing = () =>
  store.get("admin:viewing") ?? store.get("admin:editions")?.active ?? null;
export const editionOf = (row) => row.occasionId ?? row.editionId ?? null;
export const inEdition = (row, id = viewing()) => editionOf(row) === id;
export const scoped = (rows, id = viewing()) =>
  rows.filter((r) => inEdition(r, id));