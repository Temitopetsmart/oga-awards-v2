import { store } from "../../../shared/js/core/store.js";
import { pageQuery } from "./paging.js";
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

/**
 * One page of the ledger, filtered and sorted by the server: { rows, total, page, pages, stats? }.
 * Pass stats: true to also get the totals (paid, manual, money, pending, void).
 */
export async function fetchVotesPage(
  params,
  { stats = false } = {},
  editionId = viewing(),
) {
  const r = await adminApi(
    `/admin/votes?edition=${encodeURIComponent(editionId)}&${pageQuery(params, stats ? { stats: 1 } : {})}`,
  );
  return {
    rows: r.orders,
    total: r.total,
    page: r.page,
    pages: r.pages,
    stats: r.stats,
  };
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

/** Makes the winners public now, or at `at` (an ISO date-time) for a ceremony reveal. */
export const publishResults = ({ at = null, allowMissing = false } = {}) =>
  adminApi("/admin/results/publish", {
    method: "POST",
    body: { edition: viewing(), at, allowMissing },
  });

/** Takes published or scheduled results down. The reason goes in the activity log. */
export const retractResults = (reason) =>
  adminApi("/admin/results/retract", {
    method: "POST",
    body: { edition: viewing(), reason },
  });
