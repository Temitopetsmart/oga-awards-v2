import { PERM_BY_KEY } from "../config/permissions.js";
import {
  effectivePerms,
  getPerson,
  getRole,
  rolePerms,
} from "../services/team.repo.js";

/**
 * The access rule used everywhere in the admin. The PHP backend implements this same rule on
 * every request — the browser version only decides what to show.
 *
 *   allowed(permission, edition) =
 *       person is active
 *   AND (person is a Super admin OR the permission comes from their roles/grants in that edition)
 *   AND it is not blocked for them
 *   AND NOT (it changes data AND the edition is completed/archived — unless the permission is global)
 *   AND NOT (we are only previewing someone else's access — previews never change data)
 */
export function buildAccess(person, edition, viewAs) {
  let perms;
  let isSuper = false;
  let previewLabel = "";
  if (viewAs?.type === "role") {
    const role = getRole(viewAs.id);
    perms = new Set(role ? rolePerms(role) : []);
    previewLabel = `the role “${role?.name ?? "unknown"}”`;
  } else if (viewAs?.type === "person") {
    const target = getPerson(viewAs.id);
    const eff = effectivePerms(target, edition.id);
    perms = eff.perms;
    isSuper = eff.isSuper;
    previewLabel = target?.name ?? "someone";
  } else {
    const eff = effectivePerms(person, edition.id);
    perms = eff.perms;
    isSuper = eff.isSuper;
  }
  const readOnly =
    edition.status === "completed" || edition.status === "archived";
  const previewing = Boolean(viewAs);

  const can = (key) => {
    const meta = PERM_BY_KEY[key];
    if (!meta || !(isSuper || perms.has(key))) return false;
    if (
      meta.kind === "write" &&
      (previewing || (readOnly && meta.scope === "edition"))
    )
      return false;
    return true;
  };
  return {
    person,
    edition,
    can,
    canAny: (...keys) => keys.some(can),
    readOnly,
    previewing,
    previewLabel,
    isSuper: Boolean(person.superAdmin) && !previewing,
    perms,
  };
}
