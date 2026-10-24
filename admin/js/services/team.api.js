import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";
import { restoreSession } from "./admin-auth.service.js";
import { getPerson } from "./team.repo.js";

/**
 * Saving people, access and roles on the server. After every save the browser's copy is
 * re-read from the server, so every page shows exactly what is stored. The server decides
 * what is allowed; its message is shown if it says no.
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

export async function addPerson({ name, email = "", active = true }) {
  const r = await write("/admin/people", "POST", { name, email, active });
  return getPerson(r.personId);
}
export const updatePerson = (id, patch) =>
  write(`/admin/people/${id}`, "PATCH", patch);
export const setSuperAdmin = (id, value) =>
  write(`/admin/people/${id}/super`, "POST", { value });
export const deletePerson = (id) => write(`/admin/people/${id}`, "DELETE");

/** Same name as before: create or update a person's position/access in an edition. */
export const upsertAssignment = (personId, editionId, patch) =>
  write("/admin/assignments", "PUT", { personId, editionId, patch });

export async function createRole({ name, description = "", copyFrom = "" }) {
  const r = await write("/admin/roles", "POST", {
    name,
    description,
    copyFrom,
  });
  return r.roleId;
}
export const updateRole = (id, { name, description = "", perms }) =>
  write(`/admin/roles/${id}`, "PATCH", { name, description, perms });
export const resetRoleToDefault = (id) =>
  write(`/admin/roles/${id}/reset`, "POST");
export const deleteRole = (id) => write(`/admin/roles/${id}`, "DELETE");
