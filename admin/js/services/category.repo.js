// admin/js/services/category.repo.js — categories now live on the server.
import { adminApi } from "./admin-api.js";
import { viewing } from "./scope.js";

/** All categories of an edition (hidden ones too), each with its nominee count. */
export async function listCategories(editionId = viewing()) {
  try {
    const r = await adminApi(
      `/admin/categories?edition=${encodeURIComponent(editionId)}`,
    );
    return r.categories;
  } catch {
    return []; // no access to categories in that edition
  }
}

/** Map of categoryId -> number of nominees (in the edition being viewed). */
export async function nomineeCounts() {
  const counts = {};
  for (const c of await listCategories()) counts[c.id] = c.nominees;
  return counts;
}

export const getCategory = async (id) =>
  (await listCategories()).find((c) => c.id === id) ?? null;

const write = (path, method, data = {}) =>
  adminApi(path, { method, body: { edition: viewing(), ...data } });

export const createCategory = (data) =>
  write("/admin/categories", "POST", data);
export const updateCategory = (id, data) =>
  write(`/admin/categories/${id}`, "PATCH", data);
export const deleteCategory = (id) =>
  write(`/admin/categories/${id}`, "DELETE");
