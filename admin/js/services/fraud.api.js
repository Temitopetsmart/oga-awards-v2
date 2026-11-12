import { adminApi } from "./admin-api.js";

/** Anti-fraud (Phase 17). */
export const fetchFraud = (edition) =>
  adminApi(`/admin/fraud?edition=${encodeURIComponent(edition)}`);
export const saveFraudRules = (edition, rules) =>
  adminApi("/admin/fraud/rules", {
    method: "PUT",
    body: { edition, ...rules },
  });
/** action: "dismiss" | "void". `block` also blocks the address or domain behind the signal. */
export const reviewSignal = (edition, body) =>
  adminApi("/admin/fraud/review", {
    method: "POST",
    body: { edition, ...body },
  });
export const addBlock = (edition, body) =>
  adminApi("/admin/fraud/blocks", {
    method: "POST",
    body: { edition, ...body },
  });
export const removeBlock = (edition, id) =>
  adminApi(`/admin/fraud/blocks/${id}`, {
    method: "DELETE",
    body: { edition },
  });
