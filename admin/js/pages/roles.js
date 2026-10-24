import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { uid } from "../../../shared/js/core/store.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { openFormModal } from "../components/modal.js";
import { roleMatrix, readRoleMatrix } from "../components/permission-matrix.js";
import { ALL_KEYS, ROLE_TEMPLATES } from "../config/permissions.js";
import { logAudit } from "../services/admin-store.js";
import {
  listRoles,
  getRole,
  rolePerms,
  resetRole,
  roleUsage,
} from "../services/team.repo.js";
import {
  createRole,
  updateRole,
  resetRoleToDefault,
  deleteRole,
} from "../services/team.api.js";

export const title = "Roles & permissions";
export const perm = "team.view";
export const crumbs = ["Administration", "Roles & permissions"];

export default async function roles({ can }) {
  return html` <div
      class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"
    >
      <p class="mb-0 text-secondary">
        A role is a named set of permissions. Roles apply across editions; who
        holds a role is set per edition in Team & access.
      </p>
      ${can("roles.manage")
        ? html`<button class="btn btn-gold" id="newRole">
            <i class="fa-solid fa-plus me-1"></i>Create role
          </button>`
        : ""}
    </div>
    <div class="oga-roles">
      <div class="card">
        <div class="card-body">
          <ul class="oga-rolelist" id="roleList"></ul>
        </div>
      </div>
      <div class="card" id="roleEditor"></div>
    </div>`;
}

export function init(root, { admin, can, access }) {
  const manage = can("roles.manage");
  let selected = listRoles()[1]?.id ?? listRoles()[0]?.id;
  const slug = (s) =>
    s
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "");
  const lockedKeys = access.isSuper
    ? new Set()
    : new Set(ALL_KEYS.filter((k) => !access.perms.has(k))); // you can't hand out what you don't hold
  const customised = (r) => {
    const t = ROLE_TEMPLATES.find((x) => x.id === r.id);
    return (
      Boolean(t) &&
      !r.locked &&
      [...rolePerms(t)].sort().join() !== [...rolePerms(r)].sort().join()
    );
  };

  function renderList() {
    root.querySelector("#roleList").innerHTML = "";
    const ul = root.querySelector("#roleList");
    listRoles().forEach((r) => {
      const li = document.createElement("li");
      li.innerHTML =
        '<button type="button"><strong></strong><small></small></button>';
      const b = li.querySelector("button");
      b.dataset.role = r.id;
      b.classList.toggle("is-active", r.id === selected);
      b.querySelector("strong").textContent = r.name;
      b.querySelector("small").textContent =
        `${r.locked ? "Everything" : `${rolePerms(r).length} permissions`}${r.system ? "" : " · custom"}${customised(r) ? " · customised" : ""}`;
      ul.append(li);
    });
  }

  function renderEditor() {
    const r = getRole(selected);
    const el = root.querySelector("#roleEditor");
    const editable = manage && !r.locked;
    const used = roleUsage(r.id);
    el.innerHTML = "";
    el.append(
      Object.assign(document.createElement("div"), { className: "card-body" }),
    );
    const body = el.firstChild;
    body.innerHTML = `<form id="roleForm" novalidate>
      <div class="row g-3 mb-3"><div class="col-md-5"><label class="form-label" for="rName">Role name</label><input class="form-control" id="rName" name="name" ${editable ? "" : "disabled"}></div>
        <div class="col-md-7"><label class="form-label" for="rDesc">Description</label><input class="form-control" id="rDesc" name="description" ${editable ? "" : "disabled"}></div></div>
      <p class="small text-secondary" id="rNote"></p><div id="rMatrix"></div>
      <div class="d-flex flex-wrap gap-2 mt-4" id="rButtons"></div></form>`;
    body.querySelector("#rName").value = r.name;
    body.querySelector("#rDesc").value = r.description ?? "";
    body.querySelector("#rNote").textContent = r.locked
      ? "Super admin is granted to a person, not assigned as a role, and always has every permission in every edition."
      : `Used by ${used} assignment${used === 1 ? "" : "s"} across editions.${manage ? " Permissions you don’t hold yourself are greyed out — you can’t hand out more than you have." : ""}`;
    const matrixHost = body.querySelector("#rMatrix");
    matrixHost.innerHTML = roleMatrix({
      selected: new Set(rolePerms(r)),
      disabled: !editable,
      lock: lockedKeys,
    }).__raw;
    const buttons = body.querySelector("#rButtons");
    if (editable) {
      buttons.innerHTML =
        '<button class="btn btn-gold" type="submit">Save role</button>';
      if (r.system && customised(r))
        buttons.insertAdjacentHTML(
          "beforeend",
          '<button class="btn btn-ghost" type="button" data-reset>Reset to default</button>',
        );
      if (!r.system)
        buttons.insertAdjacentHTML(
          "beforeend",
          '<button class="btn btn-ghost" type="button" data-delete>Delete role</button>',
        );
    }
    body.querySelector("#roleForm").addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!editable) return;
      const name = body.querySelector("#rName").value.trim();
      if (name.length < 2) {
        notify.warning("Name needed", "Give the role a name.");
        return;
      }
      const perms = readRoleMatrix(matrixHost);
      if (!perms.length) {
        notify.warning(
          "No permissions",
          "A role needs at least one permission.",
        );
        return;
      }

      try {
        await updateRole(r.id, {
          name,
          description: body.querySelector("#rDesc").value.trim(),
          perms,
        });
      } catch (err) {
        await notify.warning("Couldn’t save the role", err.message);
        return;
      }

      notify.toast("success", "Role saved");
      renderList();
      renderEditor();
    });
    body.querySelector("[data-reset]")?.addEventListener("click", async () => {
      if (
        !(await notify.confirm({
          title: `Reset ${r.name} to its default permissions?`,
          confirmText: "Reset",
        }))
      )
        return;
      const reset = resetRole(r.id);

      if (!reset) return;

      if (!access.isSuper) {
        reset.perms = reset.perms.filter((key) => access.perms.has(key));

        if (!reset.perms.length) {
          await notify.warning(
            "Cannot reset role",
            "You do not have permission to grant any of this role's default permissions.",
          );
          return;
        }
      }

      saveRoles(listRoles().map((x) => (x.id === r.id ? reset : x)));
      logAudit(admin, "Reset role to default", r.name);
      notify.toast("success", "Role reset");
      renderList();
      renderEditor();
    });
    body.querySelector("[data-delete]")?.addEventListener("click", async () => {
      if (used > 0) {
        await notify.warning(
          "Can’t delete",
          `“${r.name}” is assigned to ${used} person${used === 1 ? "" : "s"}. Remove it from them first.`,
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: `Delete ${r.name}?`,
          text: "This can’t be undone.",
          confirmText: "Delete role",
          danger: true,
        }))
      )
        return;
      try {
        await deleteRole(r.id);
      } catch (err) {
        await notify.warning("Couldn’t delete the role", err.message);
        return;
      }
      selected = listRoles()[0].id;
      notify.toast("success", "Role deleted");
      renderList();
      renderEditor();
    });
  }

  root.querySelector("#roleList").addEventListener("click", (e) => {
    const b = e.target.closest("[data-role]");
    if (b) {
      selected = b.dataset.role;
      renderList();
      renderEditor();
    }
  });

  root.querySelector("#newRole")?.addEventListener("click", () =>
    openFormModal({
      title: "Create a role",
      submitLabel: "Create role",
      fields: [
        { name: "name", label: "Role name", required: true },
        { name: "description", label: "Description" },
        {
          name: "copyFrom",
          label: "Start from",
          placeholder: "Start empty",
          options: listRoles()
            .filter((r) => !r.locked)
            .map((r) => ({ value: r.id, label: r.name })),
        },
      ],
      schema: {
        name: [
          rules.required(),
          rules.min(2),
          rules.max(40),
          rules.custom((v) =>
            listRoles().some(
              (r) => r.name.toLowerCase() === v.trim().toLowerCase(),
            )
              ? "A role with that name already exists."
              : null,
          ),
        ],
      },
      async onSubmit(v) {
        const base = v.copyFrom
          ? rolePerms(getRole(v.copyFrom)).filter((k) => !lockedKeys.has(k))
          : ["dashboard.view"].filter((k) => !lockedKeys.has(k));
        if (!base.length) {
          await notify.warning(
            "No permissions available",
            "Choose a starting role that contains permissions you are allowed to grant.",
          );
          return;
        }
        selected = await createRole({
          name: v.name.trim(),
          description: v.description.trim(),
          copyFrom: v.copyFrom || "",
        });
        notify.toast("success", "Role created");
        renderList();
        renderEditor();
      },
    }),
  );

  renderList();
  renderEditor();
}
