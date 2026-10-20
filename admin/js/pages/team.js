import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { userError } from "../../../shared/js/core/store.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal, openCustomModal } from "../components/modal.js";
import {
  overrideMatrix,
  readOverrides,
  syncOverrides,
} from "../components/permission-matrix.js";
import { ALL_KEYS } from "../config/permissions.js";
import { logAudit } from "../services/admin-store.js";
import { setViewAs } from "../services/admin-auth.service.js";
import { editionLabel } from "../services/edition.repo.js";
import {
  listPeople,
  savePeople,
  getPerson,
  listRoles,
  getRole,
  rolePerms,
  listAssignments,
  listAllAssignments,
  saveAssignments,
  getAssignment,
  upsertAssignment,
  addPerson,
  hasAdminAccess,
} from "../services/team.repo.js";

export const title = "Team & access";
export const perm = "team.view";
export const crumbs = ["Administration", "Team & access"];

export default async function team({ can, edition, readOnly }) {
  const manage = can("team.manage") && !readOnly;
  return html` ${readOnly
      ? html`<div class="oga-banner oga-banner--warn">
          <span
            ><i class="fa-solid fa-lock me-2"></i>Access in
            ${editionLabel(edition)} is locked because the edition is
            closed.</span
          >
        </div>`
      : ""}
    <div class="card">
      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
          <button
            class="nav-link active"
            data-bs-toggle="tab"
            data-bs-target="#tab-access"
            type="button"
            role="tab"
          >
            Access in ${editionLabel(edition)}
          </button>
        </li>
        <li class="nav-item">
          <button
            class="nav-link"
            data-bs-toggle="tab"
            data-bs-target="#tab-people"
            type="button"
            role="tab"
          >
            People
          </button>
        </li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-access" role="tabpanel">
          <div
            class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 pb-0"
          >
            <p class="mb-0 text-secondary small">
              Who can sign in to the admin for this edition, and what they can
              do. Super admins have access to every edition.
            </p>
            ${manage
              ? html`<button class="btn btn-gold btn-sm" id="giveBtn">
                  <i class="fa-solid fa-user-plus me-1"></i>Give access
                </button>`
              : ""}
          </div>
          <div id="accessTable"></div>
        </div>
        <div class="tab-pane fade" id="tab-people" role="tabpanel">
          ${can("team.manage")
            ? html`<div class="d-flex justify-content-end p-3 pb-0">
                <button class="btn btn-gold btn-sm" id="addPerson">
                  <i class="fa-solid fa-plus me-1"></i>Add person
                </button>
              </div>`
            : ""}
          <div id="peopleTable"></div>
        </div>
      </div>
    </div>`;
}

export async function init(root, { admin, edition, can, readOnly, access }) {
  const manage = can("team.manage") && !readOnly;
  const roleNames = (ids) => ids.map((r) => getRole(r)?.name).filter(Boolean);

  /* Roles the current person is allowed to hand out: only ones no bigger than their own access. */
  const assignable = (r) =>
    !r.locked &&
    (access.isSuper || rolePerms(r).every((k) => access.perms.has(k)));
  const grantable = new Set(access.isSuper ? ALL_KEYS : [...access.perms]);

  /* ------------------------------------------------ Access (this edition) */
  const accessRows = () => {
    const supers = listPeople()
      .filter((p) => p.superAdmin)
      .map((p) => ({
        id: `sup-${p.id}`,
        personId: p.id,
        name: p.name,
        email: p.email,
        position: "—",
        roles: ["Super admin (all editions)"],
        allow: 0,
        block: 0,
        status: p.active ? "active" : "inactive",
        kind: "super",
      }));
    const asg = listAssignments(edition.id)
      .filter(hasAdminAccess)
      .map((a) => {
        const p = getPerson(a.personId);
        return {
          id: a.id,
          personId: a.personId,
          name: p?.name ?? "(removed)",
          email: p?.email ?? "",
          position: a.position || "—",
          roles: roleNames(a.roleIds),
          allow: a.grants.length,
          block: a.denies.length,
          status: p?.active ? "active" : "inactive",
          kind: "assignment",
        };
      });
    return [...supers, ...asg];
  };
  const canPreview = can("team.manage") || access.isSuper;
  const accessTable = new DataTable(root.querySelector("#accessTable"), {
    can,
    rows: accessRows(),
    searchKeys: ["name", "email", "position"],
    columns: [
      {
        key: "name",
        label: "Person",
        sort: true,
        render: (r) =>
          html`<strong>${r.name}</strong>${r.personId === admin.id
              ? html` <span class="oga-status oga-status--muted ms-1"
                  >You</span
                >`
              : ""}<small class="d-block text-secondary"
              >${r.email || "no email"}</small
            >`,
      },
      { key: "position", label: "Committee position", sort: true },
      {
        key: "roles",
        label: "Roles",
        render: (r) =>
          r.roles.length
            ? html`<span class="oga-chips"
                >${r.roles.map(
                  (x) => html`<span class="oga-chip">${x}</span>`,
                )}</span
              >`
            : html`<span class="text-secondary"
                >Individual permissions only</span
              >`,
      },
      {
        key: "allow",
        label: "Tweaks",
        render: (r) =>
          r.allow || r.block
            ? html`<span class="text-secondary small"
                >${r.allow ? `+${r.allow} allowed` : ""}${r.allow && r.block
                  ? " · "
                  : ""}${r.block ? `−${r.block} blocked` : ""}</span
              >`
            : "—",
      },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (r) => statusBadge(r.status),
      },
    ],
    actions: [
      {
        key: "edit",
        label: "Edit access",
        icon: "fa-pen",
        variant: "gold",
        when: (r) =>
          manage && r.kind === "assignment" && r.personId !== admin.id,
      },
      {
        key: "viewAs",
        label: "View as this person",
        icon: "fa-eye",
        when: (r) => canPreview && r.personId !== admin.id,
      },
      {
        key: "remove",
        label: "Remove access in this edition",
        icon: "fa-user-minus",
        when: (r) =>
          manage && r.kind === "assignment" && r.personId !== admin.id,
      },
    ],
    empty: "Nobody has admin access in this edition yet.",
    onAction: (key, r) => accessHandlers[key](r),
  });
  const refreshAccess = () => accessTable.setRows(accessRows());

  const rolesBody = (selected) =>
    html`<h3 class="h6">Roles</h3>
      <div class="d-grid gap-2 mb-3">
        ${listRoles()
          .filter((r) => !r.locked)
          .map((r) => {
            const ok = assignable(r);
            return html`<label class="d-flex gap-2 align-items-start"
              ><input
                class="form-check-input mt-1"
                type="checkbox"
                name="role"
                value="${r.id}"
                ${selected.has(r.id) ? "checked" : ""}
                ${ok ? "" : "disabled"}
              />
              <span
                ><strong>${r.name}</strong>${ok
                  ? ""
                  : html` <span class="oga-tag"
                      >you can’t assign this</span
                    >`}<small class="d-block text-secondary"
                  >${r.description}</small
                ></span
              ></label
            >`;
          })}
      </div>`;
  const chosenRoles = (form) =>
    [...form.querySelectorAll('input[name="role"]:checked')].map(
      (i) => i.value,
    );
  const permsOf = (roleIds) =>
    new Set(
      roleIds.flatMap((id) => {
        const r = getRole(id);
        return r ? rolePerms(r) : [];
      }),
    );
  const wireSync = (form) => {
    const sync = () => syncOverrides(form, permsOf(chosenRoles(form)));
    form
      .querySelectorAll('input[name="role"]')
      .forEach((i) => i.addEventListener("change", sync));
  };

  const accessHandlers = {
    edit(r) {
      const a = getAssignment(r.personId, edition.id);
      openCustomModal({
        title: `Access for ${r.name}`,
        submitLabel: "Save access",
        body: html`<p class="text-secondary">
            ${editionLabel(edition)} ·
            ${r.position !== "—" ? r.position : "no committee position"}
          </p>
          ${rolesBody(new Set(a.roleIds))}
          <details class="mt-3">
            <summary class="h6 mb-2">Fine-tune individual permissions</summary>
            <p class="small text-secondary">
              “Allow” adds a permission no role gives. “Block” removes a
              permission even if a role gives it — a block always wins.
            </p>
            ${overrideMatrix({
              fromRoles: permsOf(a.roleIds),
              grants: new Set(a.grants),
              denies: new Set(a.denies),
              grantable,
            })}
          </details>`,
        async onSubmit(form) {
          const roleIds = chosenRoles(form);
          const { grants, denies } = readOverrides(form);
          const fromRoles = permsOf(roleIds);
          upsertAssignment(r.personId, edition.id, {
            roleIds,
            grants: grants.filter((k) => !fromRoles.has(k)),
            denies,
          });
          logAudit(
            admin,
            "Changed access",
            `${r.name}: ${roleNames(roleIds).join(", ") || "no roles"}${grants.length ? ` +${grants.length}` : ""}${denies.length ? ` −${denies.length}` : ""}`,
          );
          notify.toast("success", "Access updated");
          refreshAccess();
        },
      });
      wireSync(document.querySelector(".modal.show form"));
    },
    viewAs(r) {
      setViewAs({ type: "person", id: r.personId });
      logAudit(admin, "Started previewing access", r.name);
      location.assign(`${ROOT}admin/index.html`);
    },
    async remove(r) {
      if (
        !(await notify.confirm({
          title: `Remove ${r.name}’s access?`,
          text: `They can no longer use the admin for ${editionLabel(edition)}. Their committee position, if any, stays.`,
          confirmText: "Remove access",
          danger: true,
        }))
      )
        return;
      upsertAssignment(r.personId, edition.id, {
        roleIds: [],
        grants: [],
        denies: [],
      });
      logAudit(admin, "Removed access", r.name);
      notify.toast("success", "Access removed");
      refreshAccess();
      refreshPeople();
    },
  };

  root.querySelector("#giveBtn")?.addEventListener("click", () => {
    const withAccess = new Set(
      listAssignments(edition.id)
        .filter(hasAdminAccess)
        .map((a) => a.personId),
    );
    const candidates = listPeople().filter(
      (p) => p.active && !p.superAdmin && !withAccess.has(p.id),
    );
    openCustomModal({
      title: `Give access in ${editionLabel(edition)}`,
      submitLabel: "Give access",
      body: html`<div class="row">
          ${field({
            label: "Person",
            name: "personId",
            required: true,
            options: [
              { value: "new", label: "+ Someone new" },
              ...candidates.map((p) => ({ value: p.id, label: p.name })),
            ],
            value: "new",
            col: "col-md-6",
          })}
          ${field({
            label: "Full name (if new)",
            name: "name",
            col: "col-md-6",
          })}
          ${field({
            label: "Email (needed to sign in)",
            name: "email",
            type: "email",
            col: "col-12",
            help: "Leave empty if the person already has an email on file.",
          })}
        </div>
        ${rolesBody(new Set())}`,
      async onSubmit(form) {
        const v = Object.fromEntries(new FormData(form));
        const roleIds = chosenRoles(form);
        if (!roleIds.length) throw userError("Choose at least one role.");
        let person;
        if (v.personId === "new") {
          if (!String(v.name).trim())
            throw userError("Enter the person’s name.");
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.email ?? ""))
            throw userError("Enter a valid email so they can sign in.");
          if (
            listPeople().some((p) => p.email === v.email.trim().toLowerCase())
          )
            throw userError(
              "Someone with that email already exists — pick them from the list.",
            );
          person = addPerson({ name: v.name, email: v.email });
        } else {
          person = getPerson(v.personId);
          if (v.email?.trim()) {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.email))
              throw userError("Enter a valid email.");
            savePeople(
              listPeople().map((p) =>
                p.id === person.id
                  ? { ...p, email: v.email.trim().toLowerCase() }
                  : p,
              ),
            );
            person = getPerson(person.id);
          }
          if (!person.email)
            throw userError(
              "This person has no email yet — enter one so they can sign in.",
            );
        }
        upsertAssignment(person.id, edition.id, { roleIds });
        logAudit(
          admin,
          "Gave access",
          `${person.name}: ${roleNames(roleIds).join(", ")}`,
        );
        notify.toast("success", `${person.name} now has access`);
        refreshAccess();
        refreshPeople();
      },
    });
  });

  /* ------------------------------------------------ People (everyone, all editions) */
  const peopleRows = () =>
    listPeople().map((p) => {
      const mine = listAllAssignments().filter(
        (a) => a.personId === p.id && (a.position || hasAdminAccess(a)),
      );
      return {
        ...p,
        status: p.active ? "active" : "inactive",
        editions: mine.length,
        superKey: p.superAdmin ? "yes" : "no",
      };
    });
  const peopleTable = new DataTable(root.querySelector("#peopleTable"), {
    can,
    rows: peopleRows(),
    searchKeys: ["name", "email"],
    filters: [
      {
        key: "status",
        label: "Status",
        options: [
          { value: "active", label: "Active" },
          { value: "inactive", label: "Inactive" },
        ],
      },
    ],
    columns: [
      {
        key: "name",
        label: "Person",
        sort: true,
        render: (p) =>
          html`<strong>${p.name}</strong>${p.superAdmin
              ? html` <span class="oga-chip ms-1">Super admin</span>`
              : ""}${p.id === admin.id
              ? html` <span class="oga-status oga-status--muted ms-1"
                  >You</span
                >`
              : ""}<small class="d-block text-secondary"
              >${p.email || "no email — can’t sign in"}</small
            >`,
      },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (p) => statusBadge(p.status),
      },
      { key: "editions", label: "Editions involved", sort: true },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "team.manage" },
      {
        key: "super",
        label: "Make / remove super admin",
        icon: "fa-crown",
        when: (p) => access.isSuper && p.id !== admin.id,
      },
      {
        key: "toggle",
        label: "Activate / deactivate",
        icon: "fa-power-off",
        perm: "team.manage",
        when: (p) => p.id !== admin.id,
      },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "team.manage",
        when: (p) => p.id !== admin.id && !p.seed,
      },
    ],
    empty: "No people yet.",
    onAction: (key, p) => peopleHandlers[key](p),
  });
  const refreshPeople = () => peopleTable.setRows(peopleRows());
  const activeSupers = (list) =>
    list.filter((p) => p.superAdmin && p.active).length;
  const personFields = [
    { name: "name", label: "Full name", required: true },
    {
      name: "email",
      label: "Email",
      type: "email",
      help: "Needed only if this person will sign in to the admin.",
    },
    { type: "switch", name: "active", label: "Active" },
  ];
  const emailTaken = (id) =>
    rules.custom((v) =>
      v &&
      listPeople().some(
        (p) => p.id !== id && p.email === v.trim().toLowerCase(),
      )
        ? "Someone with that email already exists."
        : null,
    );

  const peopleHandlers = {
    edit: (p) =>
      openFormModal({
        title: `Edit ${p.name}`,
        fields: personFields,
        values: p,
        schema: {
          name: [rules.required(), rules.min(2)],
          email: [rules.email(), emailTaken(p.id)],
        },
        async onSubmit(v) {
          const list = listPeople();
          const row = list.find((x) => x.id === p.id);
          if (
            !v.active &&
            row.superAdmin &&
            activeSupers(
              list.map((x) => (x.id === p.id ? { ...x, active: false } : x)),
            ) < 1
          )
            throw userError("At least one active Super admin is required.");
          Object.assign(row, {
            name: v.name.trim(),
            email: v.email.trim().toLowerCase(),
            active: p.id === admin.id ? true : v.active,
          });
          savePeople(list);
          logAudit(admin, "Edited person", row.name);
          notify.toast("success", "Person updated");
          refreshPeople();
          refreshAccess();
        },
      }),
    async super(p) {
      const list = listPeople();
      const row = list.find((x) => x.id === p.id);
      if (row.superAdmin && activeSupers(list) < 2) {
        await notify.warning(
          "Can’t remove",
          "At least one active Super admin is required.",
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: row.superAdmin
            ? `Remove ${p.name} as Super admin?`
            : `Make ${p.name} a Super admin?`,
          text: row.superAdmin
            ? "They keep any role they hold in individual editions."
            : "A Super admin can do everything, in every edition, including managing other admins.",
          confirmText: row.superAdmin ? "Remove" : "Make Super admin",
          danger: !row.superAdmin,
        }))
      )
        return;
      row.superAdmin = !row.superAdmin;
      savePeople(list);
      logAudit(
        admin,
        row.superAdmin ? "Made Super admin" : "Removed Super admin",
        p.name,
      );
      notify.toast("success", "Updated");
      refreshPeople();
      refreshAccess();
    },
    async toggle(p) {
      const list = listPeople();
      const row = list.find((x) => x.id === p.id);
      if (row.active && row.superAdmin && activeSupers(list) < 2) {
        await notify.warning(
          "Can’t deactivate",
          "At least one active Super admin is required.",
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: `${row.active ? "Deactivate" : "Activate"} ${p.name}?`,
          text: row.active
            ? "They can no longer sign in, in any edition. Their history is kept."
            : "",
          confirmText: row.active ? "Deactivate" : "Activate",
          danger: row.active,
        }))
      )
        return;
      row.active = !row.active;
      savePeople(list);
      logAudit(
        admin,
        row.active ? "Activated person" : "Deactivated person",
        p.name,
      );
      notify.toast("success", "Updated");
      refreshPeople();
      refreshAccess();
    },
    async delete(p) {
      if (p.editions > 0) {
        await notify.warning(
          "Can’t delete",
          `${p.name} appears in ${p.editions} edition${p.editions === 1 ? "" : "s"}. Deactivate them instead so history is kept.`,
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: `Delete ${p.name}?`,
          text: "This can’t be undone.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      savePeople(listPeople().filter((x) => x.id !== p.id));
      saveAssignments(listAllAssignments().filter((a) => a.personId !== p.id));
      logAudit(admin, "Deleted person", p.name);
      notify.toast("success", "Deleted");
      refreshPeople();
    },
  };
  root.querySelector("#addPerson")?.addEventListener("click", () =>
    openFormModal({
      title: "Add person",
      fields: personFields,
      values: { active: true },
      submitLabel: "Add person",
      schema: {
        name: [rules.required(), rules.min(2)],
        email: [rules.email(), emailTaken(null)],
      },
      async onSubmit(v) {
        addPerson({ name: v.name, email: v.email, active: v.active });
        logAudit(admin, "Added person", v.name);
        notify.toast("success", "Person added");
        refreshPeople();
      },
    }),
  );
}
