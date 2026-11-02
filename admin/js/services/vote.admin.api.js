import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";

/** Votes, payments and results on the server. */
const viewing = () => store.get("admin:viewing");

/** Every vote order of the edition (newest first). Money is 0 unless you may see finances. */
export async function fetchVotes(editionId = viewing()) {
  try {
    return (
      await adminApi(`/admin/votes?edition=${encodeURIComponent(editionId)}`)
    ).orders;
  } catch {
    return [];
  }
}

export const addManualVotes = ({ nomineeId, votes, reason }) =>
  adminApi("/admin/votes/manual", {
    method: "POST",
    body: { edition: viewing(), nomineeId, votes: Number(votes), reason },
  });

export const voidOrder = (reference, reason = "") =>
  adminApi(`/admin/votes/${encodeURIComponent(reference)}/void`, {
    method: "POST",
    body: { edition: viewing(), reason },
  });

/** "Mark as paid" = ask the payment provider again. It only counts if the provider confirms. */
export const recheckOrder = (reference) =>
  adminApi(`/admin/votes/${encodeURIComponent(reference)}/recheck`, {
    method: "POST",
    body: { edition: viewing() },
  });

/** { tally: { nomineeId: votes }, winners: [{ categoryId, nomineeId }] } */
export const fetchResults = (editionId = viewing()) =>
  adminApi(`/admin/results?edition=${encodeURIComponent(editionId)}`);

export const saveWinners = (rows) =>
  adminApi("/admin/results/winners", {
    method: "PUT",
    body: { edition: viewing(), rows },
  });
