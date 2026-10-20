import { store } from "../../../shared/js/core/store.js";

/**
 * Edition scoping for demo records. Every record belongs to an edition (occasionId / editionId);
 * older demo records without one are treated as belonging to LEGACY.
 */
export const LEGACY = "oga-10";
export const viewing = () =>
  store.get("admin:viewing") ?? store.get("admin:editions")?.active ?? LEGACY;
export const editionOf = (row) => row.occasionId ?? row.editionId ?? LEGACY;
export const inEdition = (row, id = viewing()) => editionOf(row) === id;
export const scoped = (rows, id = viewing()) =>
  rows.filter((r) => inEdition(r, id));
