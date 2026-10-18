import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { uid } from "../../../shared/js/core/store.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { ADMIN } from "../config/admin.config.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import {
  listAdmins,
  saveAdmins,
  roleLabel,
} from "../services/admin-auth.service.js";
import { logAudit } from "../services/admin-store.js";

export const title = "Admins & roles";
export const perm = "admins";
export const crumbs = ["System", "Admins & roles"];

export default async function admins() {
  return html` <div class="row g-3 mb-4">
      ${Object.entries(ADMIN.roles).map(
        ([, r]) =>
          html`<div class="col-md-4">
            <div class="oga-stat">
              <span class="oga-icon"
                ><i class="fa-solid fa-user-shield"></i
              ></span>
              <span
                ><span class="oga-stat__label">${r.label}</span
                ><span class="oga-stat__sub"
                  >${r.perms[0] === "*"
                    ? "Everything"
                    : r.perms.join(", ")}</span
                ></span
              >
            </div>
          </div>`,
      )}
    </div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Administrators</h2>
        <button class="btn btn-gold btn-sm" id="addBtn">
          <i class="fa-solid fa-plus me-1"></i>Add admin
        </button>
      </div>
      <div id="adminTable"></div>
    </div>`;
}

export async function init(root, { admin }) {
  const rows = () =>
    listAdmins().map((a) => ({
      ...a,
      roleName: roleLabel(a),
      status: a.active ? "active" : "inactive",
    }));
  const table = new DataTable(root.querySelector("#adminTable"), {
    rows: rows(),
    searchKeys: ["name", "email", "roleName"],
    filters: [
      {
        key: "role",
        label: "Role",
        options: Object.entries(ADMIN.roles).map(([value, r]) => ({
          value,
          label: r.label,
        })),
      },
    ],
    columns: [
      {
        key: "name",
        label: "Admin",
        sort: true,
        render: (a) =>
          html`<strong>${a.name}</strong>${a.id === admin.id
              ? html` <span class="oga-status oga-status--muted ms-1"
                  >You</span
                >`
              : ""}<small class="d-block text-secondary">${a.email}</small>`,
      },
      { key: "roleName", label: "Role", sort: true },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (a) => statusBadge(a.status),
      },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen" },
      {
        key: "toggle",
        label: "Activate / deactivate",
        icon: "fa-power-off",
        when: (a) => a.id !== admin.id,
      },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        when: (a) => a.id !== admin.id && !a.seed,
      },
    ],
    onAction: (key, a) => handlers[key](a),
  });
  const refresh = () => table.setRows(rows());
  const activeSupers = (list) =>
    list.filter((a) => a.active && a.role === "super_admin").length;
  const roleOptions = Object.entries(ADMIN.roles).map(([value, r]) => ({
    value,
    label: r.label,
  }));
  const fields = [
    { name: "name", label: "Full name", required: true },
    { name: "email", label: "Email", type: "email", required: true },
    { name: "role", label: "Role", required: true, options: roleOptions },
    { type: "switch", name: "active", label: "Can sign in" },
  ];
  const schemaFor = (id) => ({
    name: [rules.required(), rules.min(2)],
    role: [rules.required("Choose a role.")],
    email: [
      rules.required(),
      rules.email(),
      rules.custom((v) =>
        listAdmins().some(
          (a) => a.id !== id && a.email === v.trim().toLowerCase(),
        )
          ? "That email is already an admin."
          : null,
      ),
    ],
  });

  const handlers = {
    edit: (a) =>
      openFormModal({
        title: `Edit ${a.name}`,
        fields,
        schema: schemaFor(a.id),
        values: a,
        async onSubmit(v) {
          const list = listAdmins();
          const row = list.find((x) => x.id === a.id);
          const next = {
            ...row,
            name: v.name.trim(),
            email: v.email.trim().toLowerCase(),
            role: v.role,
            active: a.id === admin.id ? true : v.active,
          };
          if (activeSupers(list.map((x) => (x.id === a.id ? next : x))) < 1) {
            const e = new Error("At least one active Super admin is required.");
            e.userMessage = e.message;
            throw e;
          }
          Object.assign(row, next);
          saveAdmins(list);
          logAudit(admin, "Edited admin", next.email);
          notify.toast("success", "Admin updated");
          refresh();
        },
      }),
    async toggle(a) {
      const list = listAdmins();
      const row = list.find((x) => x.id === a.id);
      if (row.active && row.role === "super_admin" && activeSupers(list) < 2) {
        await notify.warning(
          "Can’t deactivate",
          "At least one active Super admin is required.",
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: `${row.active ? "Deactivate" : "Activate"} ${a.name}?`,
          confirmText: row.active ? "Deactivate" : "Activate",
          danger: row.active,
        }))
      )
        return;
      row.active = !row.active;
      saveAdmins(list);
      logAudit(
        admin,
        row.active ? "Activated admin" : "Deactivated admin",
        a.email,
      );
      notify.toast("success", "Updated");
      refresh();
    },
    async delete(a) {
      const list = listAdmins();
      if (a.role === "super_admin" && a.active && activeSupers(list) < 2) {
        await notify.warning(
          "Can’t delete",
          "At least one active Super admin is required.",
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: `Delete ${a.name}?`,
          text: "They will no longer be able to sign in.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      saveAdmins(list.filter((x) => x.id !== a.id));
      logAudit(admin, "Deleted admin", a.email);
      notify.toast("success", "Deleted");
      refresh();
    },
  };

  root.querySelector("#addBtn").addEventListener("click", () =>
    openFormModal({
      title: "Add admin",
      fields,
      schema: schemaFor(null),
      submitLabel: "Add admin",
      values: { role: "editor", active: true },
      async onSubmit(v) {
        saveAdmins([
          ...listAdmins(),
          {
            id: uid("adm-", 6),
            name: v.name.trim(),
            email: v.email.trim().toLowerCase(),
            role: v.role,
            active: v.active,
          },
        ]);
        logAudit(admin, "Added admin", v.email.trim().toLowerCase());
        notify.toast("success", "Admin added");
        refresh();
      },
    }),
  );
}
