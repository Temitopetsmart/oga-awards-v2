import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";

/** The audit log lives on the server; the browser only reads it and reports the few things it does itself. */
const viewing = () => store.get("admin:viewing");

export async function fetchAudit() {
  return (await adminApi("/admin/audit")).entries;
}

/** Call when someone downloads the audit CSV. */
export const recordAuditExport = () =>
  adminApi("/admin/audit/export", {
    method: "POST",
    body: { edition: viewing() },
  }).catch(() => {});

/** "View as…" previews: action is "start" or "stop"; target looks like "role:role-editor" or "person:12". */
export const logPreview = (action, target = "") =>
  adminApi("/admin/preview", {
    method: "POST",
    body: { edition: viewing(), action, target },
    keepalive: true,
  }).catch(() => {});
