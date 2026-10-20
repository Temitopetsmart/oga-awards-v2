import { store, uid } from "../../../shared/js/core/store.js";
import { getSeed } from "../../../shared/js/core/http.js";
import { ROLE_TEMPLATES, ALL_KEYS } from "../config/permissions.js";

/**
 * People, roles and per-edition assignments. DEMO store; the backend will keep these in
 * `people`, `roles` and `edition_assignments` tables. Keep the function names.
 *
 * people:      { id, name, email, active, superAdmin?, photo? }          (global)
 * roles:       { id, name, description, perms[], system?, locked? }      (global)
 * assignments: { id, personId, editionId, position, unitId, visible,
 *                roleIds[], grants[], denies[] }                         (per edition)
 * A person can have a committee position, admin roles, or both — in each edition separately.
 */
const PEOPLE = "admin:people";
const ROLES = "admin:roles";
const ASSIGN = "admin:assignments";

export async function ensureTeam() {
  if (
    store.get(PEOPLE) !== null &&
    store.get(ROLES) !== null &&
    store.get(ASSIGN) !== null
  )
    return;
  const committee = await getSeed("committee");
  const demo = [
    {
      id: "adm-1",
      name: "Super Admin",
      email: "admin@oga.local",
      superAdmin: true,
    },
    { id: "adm-2", name: "Demo Editor", email: "editor@oga.local" },
    { id: "adm-3", name: "Demo Gate Staff", email: "gate@oga.local" },
    { id: "adm-4", name: "Demo Treasurer", email: "treasurer@oga.local" },
    { id: "adm-5", name: "Demo Secretary", email: "secretary@oga.local" },
    {
      id: "adm-6",
      name: "Demo Publicity Director",
      email: "publicity@oga.local",
    },
  ].map((p) => ({ active: true, seed: true, ...p }));
  const asg = (personId, editionId, extra) => ({
    id: uid("ASG-"),
    personId,
    editionId,
    position: "",
    unitId: "",
    visible: false,
    roleIds: [],
    grants: [],
    denies: [],
    ...extra,
  });
  store.set(PEOPLE, [
    ...demo,
    ...committee.members.map((m) => ({
      id: m.id,
      name: m.name,
      email: "",
      active: true,
      photo: m.photo ?? null,
    })),
  ]);
  store.set(
    ROLES,
    ROLE_TEMPLATES.map((r) => ({ ...r, perms: [...r.perms], system: true })),
  );
  store.set(ASSIGN, [
    ...committee.members.map((m) =>
      asg(m.id, m.editionId, {
        position: m.role,
        unitId: m.unitId,
        visible: true,
      }),
    ),
    asg("adm-2", "oga-10", { roleIds: ["role-editor"] }),
    asg("adm-3", "oga-10", { roleIds: ["role-gate"] }),
    asg("adm-4", "oga-10", {
      position: "Treasurer",
      unitId: "u-exec",
      roleIds: ["role-treasurer"],
    }),
    asg("adm-5", "oga-10", {
      position: "Secretary",
      unitId: "u-exec",
      roleIds: ["role-secretary"],
    }),
    asg("adm-6", "oga-10", {
      position: "Director of Publicity",
      unitId: "u-media",
      roleIds: ["role-publicity"],
    }),
    asg("adm-4", "oga-9", {
      position: "Treasurer",
      unitId: "u-exec",
      roleIds: ["role-treasurer"],
    }),
    asg("adm-5", "oga-9", { position: "Member" }),
  ]);
}

export const listPeople = () => store.get(PEOPLE, []);
export const savePeople = (rows) => store.set(PEOPLE, rows);
export const getPerson = (id) => listPeople().find((p) => p.id === id) ?? null;
export const listRoles = () => store.get(ROLES, []);
export const saveRoles = (rows) => store.set(ROLES, rows);
export const getRole = (id) => listRoles().find((r) => r.id === id) ?? null;
export const listAllAssignments = () => store.get(ASSIGN, []);
export const listAssignments = (editionId) =>
  listAllAssignments().filter((a) => a.editionId === editionId);
export const saveAssignments = (rows) => store.set(ASSIGN, rows);
export const getAssignment = (personId, editionId) =>
  listAllAssignments().find(
    (a) => a.personId === personId && a.editionId === editionId,
  ) ?? null;

export const rolePerms = (role) =>
  role.perms.includes("*") ? ALL_KEYS : role.perms;
export const resetRole = (id) => {
  const t = ROLE_TEMPLATES.find((r) => r.id === id);
  return t ? { ...t, perms: [...t.perms], system: true } : null;
};

/** What one person may do in one edition: roles + extra grants, minus explicit blocks (a block always wins). */
export function effectivePerms(person, editionId) {
  if (!person?.active)
    return { isSuper: false, perms: new Set(), roleNames: [] };
  if (person.superAdmin)
    return {
      isSuper: true,
      perms: new Set(ALL_KEYS),
      roleNames: ["Super admin"],
    };
  const a = getAssignment(person.id, editionId);
  if (!a) return { isSuper: false, perms: new Set(), roleNames: [] };
  const perms = new Set();
  const roleNames = [];
  for (const rid of a.roleIds) {
    const r = getRole(rid);
    if (r) {
      rolePerms(r).forEach((k) => perms.add(k));
      roleNames.push(r.name);
    }
  }
  (a.grants ?? []).forEach((k) => perms.add(k));
  (a.denies ?? []).forEach((k) => perms.delete(k));
  return { isSuper: false, perms, roleNames };
}

/** Editions this person can open (Super admins: all; others: where they hold at least one permission). */
export const accessibleEditionIds = (person, editions) =>
  editions
    .filter(
      (e) => person.superAdmin || effectivePerms(person, e.id).perms.size > 0,
    )
    .map((e) => e.id);

/** Create or update a person's assignment in an edition (position, unit, roles, overrides …). */
export function upsertAssignment(personId, editionId, patch) {
  const rows = listAllAssignments();
  let a = rows.find(
    (x) => x.personId === personId && x.editionId === editionId,
  );
  if (!a) {
    a = {
      id: uid("ASG-"),
      personId,
      editionId,
      position: "",
      unitId: "",
      visible: false,
      roleIds: [],
      grants: [],
      denies: [],
    };
    rows.push(a);
  }
  Object.assign(a, patch);
  saveAssignments(rows);
  return a;
}

export function addPerson({ name, email = "", active = true }) {
  const p = {
    id: uid("PER-", 6),
    name: name.trim(),
    email: email.trim().toLowerCase(),
    active,
  };
  savePeople([...listPeople(), p]);
  return p;
}

/** How many assignments use a role (to stop deleting roles that are in use). */
export const roleUsage = (roleId) =>
  listAllAssignments().filter((a) => a.roleIds.includes(roleId)).length;
export const hasAdminAccess = (a) =>
  a.roleIds.length > 0 || (a.grants ?? []).length > 0;
