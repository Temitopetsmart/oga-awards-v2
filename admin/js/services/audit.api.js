import { store } from "../../../shared/js/core/store.js";
import { pageQuery } from "./paging.js";
import { adminApi } from "./admin-api.js";

/** The audit log lives on the server; the browser only reads it and reports the few things it does itself. */
const viewing = () => store.get("admin:viewing");

/** One page of the activity history, filtered and sorted by the server: { rows, total, page, pages }. */
export async function fetchAuditPage(params, { meta = false } = {}) {
  const r = await adminApi(
    `/admin/audit?${pageQuery(params, meta ? { meta: 1 } : {})}`,
  );
  return {
    rows: r.entries,
    total: r.total,
    page: r.page,
    pages: r.pages,
    admins: r.admins,
  };
}

/** Call when someone downloads the audit CSV. */
export const recordAuditExport = () =>
  adminApi("/admin/audit/export", {
    method: "POST",
    body: { edition: viewing() },
  }).catch(() => {});

/** Things the browser does by itself (CSV exports, previewing a nominee dashboard): the server checks the permission and logs them. */
export const recordActivity = (kind, target = "") =>
  adminApi("/admin/activity", {
    method: "POST",
    body: { edition: viewing(), kind, target },
    keepalive: true,
  }).catch(() => {});

/** "View as…" previews: action is "start" or "stop"; target looks like "role:role-editor" or "person:12". */
export const logPreview = (action, target = "") =>
  adminApi("/admin/preview", {
    method: "POST",
    body: { edition: viewing(), action, target },
    keepalive: true,
  }).catch(() => {});
