// admin/js/services/category.repo.js
import { collection } from "./admin-store.js";
import { listNominees } from "./nominee.repo.js";
import { viewing, inEdition } from "./scope.js";

const categories = collection("categories", {
  seed: "categories",
  prefix: "cat-",
});

export async function listCategories(editionId = viewing()) {
  return (await categories.all())
    .filter((c) => inEdition(c, editionId))
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

/** Map of categoryId -> number of nominees (in the edition being viewed). */
export async function nomineeCounts() {
  const counts = {};
  for (const n of await listNominees())
    counts[n.categoryId] = (counts[n.categoryId] ?? 0) + 1;
  return counts;
}

export const getCategory = (id) => categories.get(id);
export const createCategory = (data) =>
  categories.create({ occasionId: viewing(), ...data });
export const updateCategory = (id, data) => categories.update(id, data);
export const deleteCategory = (id) => categories.remove(id);
