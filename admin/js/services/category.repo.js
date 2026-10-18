import { collection } from "./admin-store.js";
import { listNominees } from "./nominee.repo.js";

const categories = collection("categories", {
  seed: "categories",
  prefix: "cat-",
});

export async function listCategories() {
  return (await categories.all())
    .slice()
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

/** Map of categoryId -> number of nominees. */
export async function nomineeCounts() {
  const counts = {};
  for (const n of await listNominees())
    counts[n.categoryId] = (counts[n.categoryId] ?? 0) + 1;
  return counts;
}

export const getCategory = (id) => categories.get(id);
export const createCategory = (data) =>
  categories.create({ occasionId: "oga-10", ...data });
export const updateCategory = (id, data) => categories.update(id, data);
export const deleteCategory = (id) => categories.remove(id);
