import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store, uid } from "../../../shared/js/core/store.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import { documentStore, logAudit } from "../services/admin-store.js";

export const title = "Committee";
export const perm = "committee";
export const crumbs = ["People", "Committee"];
const doc = documentStore("committee", { seed: "committee" });
const APPS = "committee-applications";

export default async function committee() {
  return html`<div class="card">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item">
        <button
          class="nav-link active"
          data-bs-toggle="tab"
          data-bs-target="#tab-apps"
          type="button"
          role="tab"
        >
          Applications
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          data-bs-toggle="tab"
          data-bs-target="#tab-members"
          type="button"
          role="tab"
        >
          Members on the site
        </button>
      </li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tab-apps" role="tabpanel">
        <div id="appsTable"></div>
      </div>
      <div class="tab-pane fade" id="tab-members" role="tabpanel">
        <div class="d-flex justify-content-end p-3 pb-0">
          <button class="btn btn-gold btn-sm" id="addMember">
            <i class="fa-solid fa-plus me-1"></i>Add member
          </button>
        </div>
        <div id="membersTable"></div>
      </div>
    </div>
  </div>`;
}

export async function init(root, { admin }) {
  const d0 = await doc.get();
  const unitName = (id) => d0.units.find((u) => u.id === id)?.name ?? "—";
  const unitOptions = d0.units.map((u) => ({ value: u.id, label: u.name }));

  /* ---- Applications */
  const apps = () =>
    store
      .get(APPS, [])
      .map((a) => ({
        ...a,
        status: a.status ?? "new",
        unitName: unitName(a.unitId),
      }))
      .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
  const patchApp = (id, p) =>
    store.set(
      APPS,
      store.get(APPS, []).map((a) => (a.id === id ? { ...a, ...p } : a)),
    );
  const appsTable = new DataTable(root.querySelector("#appsTable"), {
    rows: apps(),
    searchKeys: ["name", "email", "department", "unitName"],
    filters: [
      {
        key: "status",
        label: "Status",
        options: [
          { value: "new", label: "New" },
          { value: "accepted", label: "Accepted" },
          { value: "declined", label: "Declined" },
        ],
      },
    ],
    columns: [
      {
        key: "name",
        label: "Applicant",
        sort: true,
        render: (a) =>
          html`<strong>${a.name}</strong
            ><small class="d-block text-secondary">${a.email}</small>`,
      },
      {
        key: "department",
        label: "Department",
        sort: true,
        render: (a) => `${a.department} · ${a.level}`,
      },
      { key: "unitName", label: "Unit", sort: true },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (a) => statusBadge(a.status),
      },
      {
        key: "createdAt",
        label: "Applied",
        sort: true,
        render: (a) =>
          a.createdAt ? formatDate(a.createdAt, { dateStyle: "medium" }) : "—",
      },
    ],
    actions: [
      { key: "view", label: "Read application", icon: "fa-eye" },
      {
        key: "accept",
        label: "Accept & add to committee",
        icon: "fa-user-check",
        variant: "gold",
        when: (a) => a.status !== "accepted",
      },
      {
        key: "decline",
        label: "Decline",
        icon: "fa-ban",
        when: (a) => a.status !== "declined",
      },
      { key: "delete", label: "Delete", icon: "fa-trash" },
    ],
    empty:
      "No applications yet. They arrive from the Committee page on the public site.",
    onAction: (key, a) => appHandlers[key](a),
  });

  /* ---- Members */
  const members = async () => {
    const d = await doc.get();
    let dirty = false;
    d.members.forEach((m) => {
      if (!m.id) {
        m.id = uid("MEM-");
        dirty = true;
      }
    });
    if (dirty) doc.save(d);
    return d.members.map((m) => ({ ...m, unitName: unitName(m.unitId) }));
  };
  const membersTable = new DataTable(root.querySelector("#membersTable"), {
    rows: await members(),
    searchKeys: ["name", "role", "unitName"],
    columns: [
      {
        key: "name",
        label: "Member",
        sort: true,
        render: (m) =>
          html`<div class="oga-person">
            ${avatar(m)}
            <div><strong>${m.name}</strong><small>${m.role}</small></div>
          </div>`,
      },
      { key: "unitName", label: "Unit", sort: true },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen" },
      { key: "delete", label: "Remove", icon: "fa-trash" },
    ],
    empty: "No members yet.",
    onAction: (key, m) => memberHandlers[key](m),
  });

  const refreshApps = () => appsTable.setRows(apps());
  const refreshMembers = async () => membersTable.setRows(await members());
  const memberFields = [
    { name: "name", label: "Full name", required: true },
    { name: "role", label: "Role / title", required: true },
    { name: "unitId", label: "Unit", required: true, options: unitOptions },
  ];
  const memberSchema = {
    name: [rules.required(), rules.min(2)],
    role: [rules.required()],
    unitId: [rules.required("Choose a unit.")],
  };

  const appHandlers = {
    view: (a) => {
      openInfoModal({
        title: a.name,
        body: html`<dl class="oga-dl">
            <div>
              <dt>Email</dt>
              <dd>${a.email}</dd>
            </div>
            <div>
              <dt>Phone</dt>
              <dd>${a.phone}</dd>
            </div>
            <div>
              <dt>Department</dt>
              <dd>${a.department} · ${a.level}</dd>
            </div>
            <div>
              <dt>Preferred unit</dt>
              <dd>${a.unitName}</dd>
            </div>
            <div>
              <dt>Status</dt>
              <dd>${statusBadge(a.status)}</dd>
            </div>
          </dl>
          <h3 class="h6 mt-3">Why they want to join</h3>
          <p class="mb-0">${a.motivation}</p>`,
      });
    },
    accept: (a) =>
      openFormModal({
        title: `Add ${a.name} to the committee`,
        fields: memberFields,
        schema: memberSchema,
        values: { name: a.name, role: "Member", unitId: a.unitId },
        submitLabel: "Accept & add",
        async onSubmit(v) {
          const d = await doc.get();
          d.members.push({
            id: uid("MEM-"),
            name: v.name.trim(),
            role: v.role.trim(),
            unitId: v.unitId,
            photo: null,
          });
          doc.save(d);
          patchApp(a.id, { status: "accepted" });
          logAudit(admin, "Accepted committee application", a.name);
          notify.toast("success", `${a.name} added to the committee`);
          refreshApps();
          await refreshMembers();
        },
      }),
    async decline(a) {
      if (
        !(await notify.confirm({
          title: `Decline ${a.name}?`,
          confirmText: "Decline",
          danger: true,
        }))
      )
        return;
      patchApp(a.id, { status: "declined" });
      logAudit(admin, "Declined committee application", a.name);
      notify.toast("info", "Declined");
      refreshApps();
    },
    async delete(a) {
      if (
        !(await notify.confirm({
          title: `Delete this application?`,
          text: "This can’t be undone.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      store.set(
        APPS,
        store.get(APPS, []).filter((x) => x.id !== a.id),
      );
      logAudit(admin, "Deleted committee application", a.name);
      notify.toast("success", "Deleted");
      refreshApps();
    },
  };
  const memberHandlers = {
    edit: (m) =>
      openFormModal({
        title: `Edit ${m.name}`,
        fields: memberFields,
        schema: memberSchema,
        values: m,
        async onSubmit(v) {
          const d = await doc.get();
          Object.assign(
            d.members.find((x) => x.id === m.id),
            { name: v.name.trim(), role: v.role.trim(), unitId: v.unitId },
          );
          doc.save(d);
          logAudit(admin, "Edited committee member", v.name);
          notify.toast("success", "Member updated");
          await refreshMembers();
        },
      }),
    async delete(m) {
      if (
        !(await notify.confirm({
          title: `Remove ${m.name}?`,
          text: "They’ll disappear from the public Committee page.",
          confirmText: "Remove",
          danger: true,
        }))
      )
        return;
      const d = await doc.get();
      d.members = d.members.filter((x) => x.id !== m.id);
      doc.save(d);
      logAudit(admin, "Removed committee member", m.name);
      notify.toast("success", "Removed");
      await refreshMembers();
    },
  };
  root.querySelector("#addMember").addEventListener("click", () =>
    openFormModal({
      title: "Add member",
      fields: memberFields,
      schema: memberSchema,
      submitLabel: "Add member",
      async onSubmit(v) {
        const d = await doc.get();
        d.members.push({
          id: uid("MEM-"),
          name: v.name.trim(),
          role: v.role.trim(),
          unitId: v.unitId,
          photo: null,
        });
        doc.save(d);
        logAudit(admin, "Added committee member", v.name);
        notify.toast("success", "Member added");
        await refreshMembers();
      },
    }),
  );
}
