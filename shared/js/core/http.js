import { recordActivity } from "./audit.api.js";

/* The audit trail lives on the server, which records every change itself. The only thing the browser still
   reports is previewing a nominee's dashboard (CSV exports are reported from main.js). Everything else is a no-op. */
const BROWSER_SIDE = { "Previewed nominee dashboard": "preview:nominee" };
export function logAudit(admin, action, target = "") {
  const kind = BROWSER_SIDE[action];
  if (kind) recordActivity(kind, target);
}
export const getAudit = () => [];
