import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";
import { restoreSession } from "./admin-auth.service.js";
import { getEdition } from "./edition.repo.js";

/**
 * Saving editions and occasions on the server. After every save the browser's copy is
 * re-read from the server, so every page shows exactly what is stored.
 */
const viewing = () => store.get("admin:viewing");

async function write(path, method, body = {}) {
  const res = await adminApi(path, {
    method,
    body: { edition: viewing(), ...body },
  });
  await restoreSession();
  return res;
}

/** Runs a save and answers { ok } / { ok:false, error } instead of throwing (the pages show the message). */
const attempt = async (fn) => {
  try {
    await fn();
    return { ok: true };
  } catch (err) {
    return { ok: false, error: err.message };
  }
};

export async function createEdition({
  name,
  edition,
  year,
  tagline = "",
  copyFrom = null,
  copy = {},
}) {
  const r = await write("/admin/editions", "POST", {
    name,
    label: edition,
    year: Number(year),
    tagline,
    copyFrom,
    copy,
  });
  return (
    getEdition(r.editionId) ?? {
      id: r.editionId,
      name,
      edition,
      year: Number(year),
    }
  );
}

export const updateEditionDetails = (
  id,
  { name, edition, year, tagline = "" },
) =>
  write(`/admin/editions/${id}`, "PATCH", {
    name,
    label: edition,
    year: Number(year),
    tagline,
  });

/** mode, forceState, timeline { nominationsOpen… ceremony }, features { tickets, committeeRecruitment, showVoteCounts } */
export const saveEditionState = (id, state) =>
  write(`/admin/editions/${id}/state`, "PUT", state);

export const saveVotingRules = (id, rules) =>
  write(`/admin/editions/${id}/voting`, "PUT", rules);

/** to: "active" | "completed" | "archived". Reopening a completed edition needs a reason (Super admin only). */
export const transition = (id, to, reason = "") =>
  attempt(() =>
    write(`/admin/editions/${id}/transition`, "POST", { to, reason }),
  );

export const deleteDraftEdition = (id) =>
  attempt(() => write(`/admin/editions/${id}`, "DELETE"));

export const createOccasion = (occasion) =>
  write("/admin/occasions", "POST", { occasion });
export const updateOccasion = (id, occasion) =>
  write(`/admin/occasions/${id}`, "PATCH", { occasion });
export const toggleOccasion = (id) =>
  write(`/admin/occasions/${id}/toggle`, "POST");
export const deleteOccasion = (id) => write(`/admin/occasions/${id}`, "DELETE");
