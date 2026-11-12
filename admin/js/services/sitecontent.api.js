import { adminApi, adminUpload } from "./admin-api.js";

const q = (edition) => `?edition=${encodeURIComponent(edition)}`;

/** The content manager (Phase 16). `edition` is only used to check the person's permission. */
export const listPages = async (edition) =>
  (await adminApi(`/admin/content${q(edition)}`)).pages;
export const readPage = async (edition, name) =>
  (await adminApi(`/admin/content/${name}${q(edition)}`)).content;
export const savePage = (edition, name, body) =>
  adminApi(`/admin/content/${name}`, {
    method: "PUT",
    body: { edition, ...body },
  });
export const resetPage = (edition, name) =>
  adminApi(`/admin/content/${name}`, { method: "DELETE", body: { edition } });

/** Uploads one gallery picture (the server checks and resizes it). Returns the saved path. */
export async function uploadGalleryImage(edition, file) {
  if (file.size > 5 * 1024 * 1024)
    throw new Error("That picture is too large (maximum 5 MB).");
  const form = new FormData();
  form.append("image", file);
  const r = await adminUpload(
    `/admin/content/gallery/image${q(edition)}`,
    form,
  );
  return r.path ?? r;
}
