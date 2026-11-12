import { adminApi } from "./admin-api.js";

const send = (path, method, body) => adminApi(path, { method, body });
const q = (edition) => `?edition=${encodeURIComponent(edition)}`;

/** Announcements (Phase 14). */
export const listAnnouncements = async (edition) =>
  (await adminApi(`/admin/announcements${q(edition)}`)).announcements;
export const saveAnnouncement = (edition, id, body) =>
  id
    ? send(`/admin/announcements/${id}`, "PATCH", { edition, ...body })
    : send("/admin/announcements", "POST", { edition, ...body });
export const deleteAnnouncement = (edition, id) =>
  send(`/admin/announcements/${id}`, "DELETE", { edition });

/** Face of the period (Phase 15). */
export const listFaces = async (edition) =>
  (await adminApi(`/admin/spotlight${q(edition)}`)).faces;
export const saveFace = (edition, id, body) =>
  id
    ? send(`/admin/spotlight/${id}`, "PATCH", { edition, ...body })
    : send("/admin/spotlight", "POST", { edition, ...body });
export const featureFace = (id) => send(`/admin/spotlight/${id}/feature`, "POST", {});
export const deleteFace = (edition, id) =>
  send(`/admin/spotlight/${id}`, "DELETE", { edition });