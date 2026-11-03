import { userError } from "../core/store.js";
import { APP } from "../config/app.config.js";

/**
 * Public side of committee recruitment, served by the PHP backend: is the window open, and the
 * apply form. (Reviewing applications is in admin/js/services/committee.api.js.)
 */
export const COMMITTEE_STAGES = {
  submitted: "Application received",
  shortlisted: "Shortlisted for screening",
  screened: "Screening completed",
  approved: "Approved",
  reserve: "Reserve list",
  unsuccessful: "Not selected",
  reconsideration: "Reconsideration",
  appointed: "Appointed",
  withdrawn: "Withdrawn",
};

async function request(path, options = {}) {
  let res;
  try {
    res = await fetch(`${APP.data.apiBase}${path}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      ...options,
    });
  } catch {
    throw userError("Could not reach the server. Check your connection.");
  }
  let data = null;
  try {
    data = await res.json();
  } catch {
    /* not JSON */
  }
  if (!res.ok || data?.ok === false)
    throw userError(data?.error ?? "Something went wrong. Please try again.");
  return data;
}

/** { open, reason, received, config } — the server decides whether applications are open. */
export async function getApplicationWindow(editionId) {
  const r = await request(
    `/committee/recruitment?edition=${encodeURIComponent(editionId)}`,
  );
  return {
    open: r.open,
    reason: r.reason,
    received: r.received,
    config: r.config,
  };
}

/** values: the form fields; photo: an optional File. The server re-checks everything. */
export async function submitRecruitmentApplication(values, photo) {
  const form = new FormData();
  for (const [key, value] of Object.entries(values)) {
    if (value !== undefined && value !== null) form.append(key, value);
  }
  if (photo) form.append("photo", photo);
  return request("/committee/applications", { method: "POST", body: form });
}
