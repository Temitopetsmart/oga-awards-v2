import { getResource } from "../core/http.js";
import { store } from "../core/store.js";

export async function getCategories(occasionId) {
  return (await getResource("categories"))
    .filter(
      (category) =>
        category.occasionId === occasionId && category.active !== false,
    )
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

/**
 * Return approved nominees belonging to this edition only.
 * Public registrations must retain their original occasionId.
 */
async function allNominees(occasionId) {
  const [seeded] = await Promise.all([getResource("nominees")]);

  const registered = store
    .get("nominees", [])
    .filter(
      (nominee) =>
        nominee.occasionId === occasionId && nominee.status === "approved",
    );

  const existing = seeded.filter(
    (nominee) =>
      nominee.occasionId === occasionId && nominee.status === "approved",
  );

  const ids = new Set(existing.map((nominee) => nominee.id));

  return [...existing, ...registered.filter((nominee) => !ids.has(nominee.id))];
}

export async function getNominees(occasionId, { categoryId } = {}) {
  return (await allNominees(occasionId)).filter(
    (nominee) => !categoryId || nominee.categoryId === categoryId,
  );
}

export async function getWinners(occasionId) {
  const [winners, categories, nominees] = await Promise.all([
    getResource("winners"),
    getCategories(occasionId),
    allNominees(occasionId),
  ]);

  return winners
    .filter((winner) => winner.occasionId === occasionId)
    .map((winner) => ({
      category: categories.find(
        (category) => category.id === winner.categoryId,
      ),
      nominee: nominees.find((nominee) => nominee.id === winner.nomineeId),
    }))
    .filter((winner) => winner.category && winner.nominee)
    .sort((a, b) => (a.category.order ?? 0) - (b.category.order ?? 0));
}

/** The committee page: intro and units for everyone, and the members of one edition. */
export async function getCommittee(editionId) {
  const file = await getResource("committee");
  return {
    ...file,
    members: (file.members ?? []).filter(
      (member) => member.editionId === editionId,
    ),
  };
}
