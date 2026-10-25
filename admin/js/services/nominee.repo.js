// admin/js/services/nominee.repo.js — nominees now live on the server.
import { store } from "../../../shared/js/core/store.js";
import { adminApi, adminUpload } from "./admin-api.js";
import { restoreSession } from "./admin-auth.service.js";
import { viewing } from "./scope.js";

/** All nominees of an edition (every status), with self-registered ones carrying their account email. */
export async function listNominees(editionId = viewing()) {
  try {
    const r = await adminApi(
      `/admin/nominees?edition=${encodeURIComponent(editionId)}`,
    );
    return r.nominees;
  } catch {
    return []; // no access to nominees in that edition
  }
}

/** Pending count for the sidebar badge. Refreshed with every sign-in check and after every save. */
export const pendingNomineeCount = (editionId = viewing()) =>
  store.get("admin:pending", {})?.[editionId] ?? 0;

const FIELDS = [
  "name",
  "department",
  "categoryId",
  "status",
  "bio",
  "level",
  "phone",
];

async function write(path, method, body = {}) {
  const r = await adminApi(path, {
    method,
    body: { edition: viewing(), ...body },
  });
  await restoreSession(); // re-reads the pending badge and everything else
  return r;
}

/** One id or a list of ids. Approve / reject / set pending. */
export const setNomineeStatus = (ids, status) =>
  write("/admin/nominees/status", "POST", { ids: [].concat(ids), status });

/** id = null adds a new nominee; otherwise edits the nominee. */
export async function saveNominee(id, data) {
  const body = Object.fromEntries(
    FIELDS.filter((k) => Object.hasOwn(data, k)).map((k) => [k, data[k]]),
  );
  if (id) await write(`/admin/nominees/${id}`, "PATCH", body);
  else await write("/admin/nominees", "POST", body);
}

export const removeNominee = (id) => write(`/admin/nominees/${id}`, "DELETE");

/** file: a File from an <input type="file">. The server checks and resizes it. */
export async function uploadNomineePhoto(id, file) {
  if (file.size > 5 * 1024 * 1024)
    throw new Error("That photo is too large (maximum 5 MB).");
  const form = new FormData();
  form.append("photo", file);
  await adminUpload(`/admin/nominees/${id}/photo`, form);
  await restoreSession();
}

export const removeNomineePhoto = (id) =>
  write(`/admin/nominees/${id}/photo`, "DELETE");
