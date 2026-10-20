import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import {
  listNominees,
  setNomineeStatus,
  saveNominee,
  removeNominee,
} from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import { logAudit } from "../services/admin-store.js";

export const title = "Nominees";
export const perm = "nominees.view";
export const crumbs = ["Awards", "Nominees"];

const STATUSES = [
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
];

export default async function nominees({ can }) {
  return html`<div class="card">
    <div
      class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
    >
      <h2 class="card-title mb-0">All nominees</h2>
      <div class="d-flex gap-2">
        ${can("nominees.export")
          ? html`<button class="btn btn-ghost btn-sm" id="exportBtn">
              <i class="fa-solid fa-file-csv me-1"></i>Export CSV
            </button>`
          : ""}
        ${can("nominees.create")
          ? html`<button class="btn btn-gold btn-sm" id="addBtn">
              <i class="fa-solid fa-plus me-1"></i>Add nominee
            </button>`
          : ""}
      </div>
    </div>
    <div id="nomTable"></div>
  </div>`;
}

export async function init(root, { admin, can }) {
  const cats = await listCategories();
  const catName = (id) => cats.find((c) => c.id === id)?.name ?? "—";
  const load = async () =>
    (await listNominees()).map((n) => ({
      ...n,
      categoryName: catName(n.categoryId),
    }));

  const table = new DataTable(root.querySelector("#nomTable"), {
    can,
    rows: await load(),
    searchKeys: ["name", "email", "department", "categoryName"],
    filters: [
      { key: "status", label: "Status", options: STATUSES },
      {
        key: "categoryId",
        label: "Category",
        options: cats.map((c) => ({ value: c.id, label: c.name })),
      },
    ],
    columns: [
      {
        key: "name",
        label: "Nominee",
        sort: true,
        render: (n) =>
          html`<div class="oga-person">
            ${avatar(n)}
            <div>
              <strong>${n.name}</strong
              ><small>${n.email ?? n.department ?? ""}</small>
            </div>
          </div>`,
      },
      { key: "categoryName", label: "Category", sort: true },
      { key: "department", label: "Department", sort: true },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (n) => statusBadge(n.status),
      },
      {
        key: "createdAt",
        label: "Added",
        sort: true,
        render: (n) =>
          n.createdAt
            ? formatDate(n.createdAt, { dateStyle: "medium" })
            : "Admin",
      },
    ],
    actions: [
      {
        key: "view",
        label: "View details",
        icon: "fa-eye",
        perm: "nominees.view",
      },
      {
        key: "viewAs",
        label: "View as nominee",
        icon: "fa-user-secret",
        perm: "nominees.view",
      },
      {
        key: "approve",
        label: "Approve",
        icon: "fa-check",
        variant: "gold",
        perm: "nominees.approve",
        when: (n) => n.status !== "approved",
      },
      {
        key: "reject",
        label: "Reject",
        icon: "fa-ban",
        perm: "nominees.approve",
        when: (n) => n.status !== "rejected",
      },
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "nominees.edit" },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "nominees.delete",
      },
    ],
    bulkActions: [
      {
        key: "approve",
        label: "Approve",
        icon: "fa-check",
        perm: "nominees.approve",
      },
      {
        key: "reject",
        label: "Reject",
        icon: "fa-ban",
        perm: "nominees.approve",
      },
    ],
    empty: "No nominees yet in this edition.",
    onAction: (key, n) => handlers[key](n),
    onBulk: (key, list) => bulk(key, list),
  });
  const refresh = async () => table.setRows(await load());

  const fields = () => [
    { name: "name", label: "Full name", required: true },
    { name: "department", label: "Department", required: true },
    {
      name: "categoryId",
      label: "Category",
      required: true,
      options: cats.map((c) => ({ value: c.id, label: c.name })),
    },
    { name: "status", label: "Status", required: true, options: STATUSES },
    {
      name: "bio",
      label: "Bio",
      type: "textarea",
      rows: 4,
      help: "Shown on the public profile.",
    },
  ];
  const schema = {
    name: [rules.required(), rules.min(2)],
    department: [rules.required()],
    categoryId: [rules.required("Choose a category.")],
    status: [rules.required()],
    bio: [rules.max(500)],
  };

  const setStatus = async (n, status) => {
    await setNomineeStatus(n.id, status);
    logAudit(
      admin,
      status === "approved" ? "Approved nominee" : "Rejected nominee",
      n.name,
    );
  };

  const handlers = {
    view: (n) =>
      openInfoModal({
        title: n.name,
        body: html`<dl class="oga-dl">
          <div>
            <dt>Status</dt>
            <dd>${statusBadge(n.status)}</dd>
          </div>
          <div>
            <dt>Category</dt>
            <dd>${n.categoryName}</dd>
          </div>
          <div>
            <dt>Department</dt>
            <dd>${n.department ?? "—"}</dd>
          </div>
          ${n.level
            ? html`<div>
                <dt>Level</dt>
                <dd>${n.level}</dd>
              </div>`
            : ""}
          ${n.email
            ? html`<div>
                <dt>Email</dt>
                <dd>${n.email}</dd>
              </div>`
            : ""}
          ${n.phone
            ? html`<div>
                <dt>Phone</dt>
                <dd>${n.phone}</dd>
              </div>`
            : ""}
          <div>
            <dt>Source</dt>
            <dd>
              ${n.source === "registration"
                ? "Self-registered"
                : "Added by admin"}
            </dd>
          </div>
          <div>
            <dt>Bio</dt>
            <dd>${n.bio || "—"}</dd>
          </div>
        </dl>`,
      }),
    viewAs: (n) => {
      logAudit(admin, "Previewed nominee dashboard", n.name);
      window.open(
        `${ROOT}dashboard.html?viewAs=${encodeURIComponent(n.id)}`,
        "_blank",
        "noopener",
      );
    },
    async approve(n) {
      await setStatus(n, "approved");
      notify.toast("success", `${n.name} approved`);
      refresh();
    },
    async reject(n) {
      if (
        !(await notify.confirm({
          title: `Reject ${n.name}?`,
          text: "They won’t appear on the public site.",
          confirmText: "Reject",
          danger: true,
        }))
      )
        return;
      await setStatus(n, "rejected");
      notify.toast("info", `${n.name} rejected`);
      refresh();
    },
    edit: (n) =>
      openFormModal({
        title: `Edit ${n.name}`,
        fields: fields(),
        values: n,
        schema,
        async onSubmit(v) {
          await saveNominee(n.id, v);
          logAudit(admin, "Edited nominee", v.name);
          notify.toast("success", "Nominee updated");
          await refresh();
        },
      }),
    async delete(n) {
      if (
        !(await notify.confirm({
          title: `Delete ${n.name}?`,
          text: "This can’t be undone.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      await removeNominee(n.id);
      logAudit(admin, "Deleted nominee", n.name);
      notify.toast("success", "Nominee deleted");
      refresh();
    },
  };

  async function bulk(key, list) {
    const status = key === "approve" ? "approved" : "rejected";
    if (
      !(await notify.confirm({
        title: `${key === "approve" ? "Approve" : "Reject"} ${list.length} nominee${list.length === 1 ? "" : "s"}?`,
        confirmText: key === "approve" ? "Approve" : "Reject",
        danger: key === "reject",
      }))
    )
      return;
    for (const n of list) await setNomineeStatus(n.id, status);
    logAudit(
      admin,
      `${key === "approve" ? "Approved" : "Rejected"} ${list.length} nominees`,
      "bulk action",
    );
    notify.toast("success", `${list.length} updated`);
    refresh();
  }

  root.querySelector("#addBtn")?.addEventListener("click", () =>
    openFormModal({
      title: "Add nominee",
      fields: fields(),
      values: { status: "approved" },
      schema,
      submitLabel: "Add nominee",
      async onSubmit(v) {
        await saveNominee(null, v);
        logAudit(admin, "Added nominee", v.name);
        notify.toast("success", "Nominee added");
        await refresh();
      },
    }),
  );

  root.querySelector("#exportBtn")?.addEventListener("click", async () => {
    downloadCsv("oga-nominees.csv", await load(), [
      { label: "Name", key: "name" },
      { label: "Category", key: "categoryName" },
      { label: "Department", key: "department" },
      { label: "Email", key: "email" },
      { label: "Phone", key: "phone" },
      { label: "Status", key: "status" },
    ]);
    logAudit(admin, "Exported nominees");
  });
}
