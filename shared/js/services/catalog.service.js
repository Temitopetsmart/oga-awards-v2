import { getResource } from "../core/http.js";
import { store } from "../core/store.js";

export async function getCategories(occasionId) {
  return (await getResource("categories"))
    .filter((c) => c.occasionId === occasionId && c.active !== false)
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

/**
 * Approved nominees. DEMO BRIDGE: nominees who registered on this site (kept in this browser)
 * and were approved in the admin are included. Remove with the backend.
 */
async function allNominees(occasionId) {
  const seeded = (await getResource("nominees")).filter(
    (n) => n.occasionId === occasionId && n.status === "approved",
  );
  const registered = store
    .get("nominees", [])
    .filter((n) => n.status === "approved")
    .map((n) => ({ ...n, occasionId }));
  return [...seeded, ...registered];
}

export async function getNominees(occasionId, { categoryId } = {}) {
  return (await allNominees(occasionId)).filter(
    (n) => !categoryId || n.categoryId === categoryId,
  );
}

/** Winners joined with their category and nominee records. */
export async function getWinners(occasionId) {
  const [winners, categories, nominees] = await Promise.all([
    getResource("winners"),
    getCategories(occasionId),
    allNominees(occasionId),
  ]);
  return winners
    .filter((w) => w.occasionId === occasionId)
    .map((w) => ({
      category: categories.find((c) => c.id === w.categoryId),
      nominee: nominees.find((n) => n.id === w.nomineeId),
    }))
    .filter((w) => w.category && w.nominee)
    .sort((a, b) => (a.category.order ?? 0) - (b.category.order ?? 0));
}
