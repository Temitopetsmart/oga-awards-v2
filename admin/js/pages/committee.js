import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store } from "../../../shared/js/core/store.js";
import { getResource } from "../../../shared/js/core/http.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import { logAudit } from "../services/admin-store.js";
import {
  listPeople,
  getPerson,
  getRole,
  listAssignments,
  saveAssignments,
  listAllAssignments,
  upsertAssignment,
  addPerson,
  hasAdminAccess,
} from "../services/team.repo.js";
import { scoped } from "../services/scope.js";

export const title = "Committee";
export const perm = "committee.view";
export const crumbs = ["People", "Committee"];
const APPS = "committee-applications";

export default async function committee({ can }) {
  return html`<div class="card">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item">
        <button
          class="nav-link active"
          data-bs-toggle="tab"
          data-bs-target="#tab-members"
          type="button"
          role="tab"
        >
          Members
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          data-bs-toggle="tab"
          data-bs-target="#tab-apps"
          type="button"
          role="tab"
        >
          Applications
        </button>
      </li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tab-members" role="tabpanel">
        ${can("committee.manage")
          ? html`<div class="d-flex justify-content-end p-3 pb-0">
              <button class="btn btn-gold btn-sm" id="addMember">
                <i class="fa-solid fa-plus me-1"></i>Add member
              </button>
            </div>`
          : ""}
        <div id="membersTable"></div>
      </div>
      <div class="tab-pane fade" id="tab-apps" role="tabpanel">
        <div id="appsTable"></div>
      </div>
    </div>
  </div>`;
}

export async function init(root, { admin, edition, can }) {
  const units = (await getResource("committee")).units;
  const unitName = (id) => units.find((u) => u.id === id)?.name ?? "—";
  const unitOptions = units.map((u) => ({ value: u.id, label: u.name }));

  /* ---- Members of this edition's committee */
  const members = () =>
    listAssignments(edition.id)
      .filter((a) => a.position)
      .map((a) => {
        const p = getPerson(a.personId);
        return {
          ...a,
          name: p?.name ?? "(removed)",
          unitName: unitName(a.unitId),
          roles: a.roleIds.map((r) => getRole(r)?.name).filter(Boolean),
          visibleKey: a.visible ? "live" : "hidden",
        };
      });
  const membersTable = new DataTable(root.querySelector("#membersTable"), {
    can,
    rows: members(),
    searchKeys: ["name", "position", "unitName"],
    filters: [{ key: "unitId", label: "Unit", options: unitOptions }],
    columns: [
      {
        key: "name",
        label: "Member",
        sort: true,
        render: (m) =>
          html`<div class="oga-person">
            ${avatar(m)}
            <div><strong>${m.name}</strong><small>${m.position}</small></div>
          </div>`,
      },
      { key: "unitName", label: "Unit", sort: true },
      {
        key: "roles",
        label: "Admin access",
        render: (m) =>
          m.roles.length
            ? html`<span class="oga-chips"
                >${m.roles.map(
                  (r) => html`<span class="oga-chip">${r}</span>`,
                )}</span
              >`
            : html`<span class="text-secondary">None</span>`,
      },
      {
        key: "visibleKey",
        label: "On public site",
        sort: true,
        render: (m) =>
          statusBadge(m.visibleKey, m.visible ? "Shown" : "Hidden"),
      },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "committee.manage" },
      {
        key: "remove",
        label: "Remove from committee",
        icon: "fa-user-minus",
        perm: "committee.manage",
      },
    ],
    empty: "No committee members yet in this edition.",
    onAction: (key, m) => memberHandlers[key](m),
  });

  /* ---- Applications */
  const apps = () =>
    scoped(store.get(APPS, []))
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
    can,
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
      {
        key: "view",
        label: "Read application",
        icon: "fa-eye",
        perm: "committee.view",
      },
      {
        key: "accept",
        label: "Accept & add to committee",
        icon: "fa-user-check",
        variant: "gold",
        perm: "committee.review",
        when: (a) => a.status !== "accepted",
      },
      {
        key: "decline",
        label: "Decline",
        icon: "fa-ban",
        perm: "committee.review",
        when: (a) => a.status !== "declined",
      },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "committee.review",
      },
    ],
    empty:
      "No applications yet in this edition. They arrive from the Committee page on the public site.",
    onAction: (key, a) => appHandlers[key](a),
  });

  const refreshMembers = () => membersTable.setRows(members());
  const refreshApps = () => appsTable.setRows(apps());

  const positionFields = [
    {
      name: "position",
      label: "Position",
      required: true,
      help: "e.g. Chairperson, Treasurer, Director of Publicity",
    },
    { name: "unitId", label: "Unit", required: true, options: unitOptions },
    {
      type: "switch",
      name: "visible",
      label: "Show on the public Committee page",
    },
  ];
  const positionSchema = {
    position: [rules.required(), rules.max(60)],
    unitId: [rules.required("Choose a unit.")],
  };

  const memberHandlers = {
    edit: (m) =>
      openFormModal({
        title: `Edit ${m.name}`,
        fields: positionFields,
        schema: positionSchema,
        values: m,
        async onSubmit(v) {
          upsertAssignment(m.personId, edition.id, {
            position: v.position.trim(),
            unitId: v.unitId,
            visible: v.visible,
          });
          logAudit(admin, "Edited committee member", m.name);
          notify.toast("success", "Member updated");
          refreshMembers();
        },
      }),
    async remove(m) {
      if (
        !(await notify.confirm({
          title: `Remove ${m.name}?`,
          text: hasAdminAccess(m)
            ? "They leave the committee list but keep their admin access."
            : "They’ll disappear from this edition’s committee.",
          confirmText: "Remove",
          danger: true,
        }))
      )
        return;
      if (hasAdminAccess(m))
        upsertAssignment(m.personId, edition.id, {
          position: "",
          unitId: "",
          visible: false,
        });
      else saveAssignments(listAllAssignments().filter((a) => a.id !== m.id));
      logAudit(admin, "Removed committee member", m.name);
      notify.toast("success", "Removed");
      refreshMembers();
    },
  };

  const appHandlers = {
    view: (a) =>
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
      }),
    accept: (a) =>
      openFormModal({
        title: `Add ${a.name} to the committee`,
        submitLabel: "Accept & add",
        schema: positionSchema,
        values: { position: "Member", unitId: a.unitId, visible: true },
        fields: positionFields,
        async onSubmit(v) {
          const person =
            listPeople().find(
              (p) => a.email && p.email === a.email.toLowerCase(),
            ) ?? addPerson({ name: a.name, email: a.email, active: true });
          upsertAssignment(person.id, edition.id, {
            position: v.position.trim(),
            unitId: v.unitId,
            visible: v.visible,
          });
          patchApp(a.id, { status: "accepted" });
          logAudit(admin, "Accepted committee application", a.name);
          notify.toast("success", `${a.name} added to the committee`);
          refreshApps();
          refreshMembers();
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
          title: "Delete this application?",
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

  root.querySelector("#addMember")?.addEventListener("click", () => {
    const taken = new Set(
      listAssignments(edition.id)
        .filter((a) => a.position)
        .map((a) => a.personId),
    );
    openFormModal({
      title: "Add committee member",
      submitLabel: "Add member",
      values: { personId: "new", visible: true },
      fields: [
        {
          name: "personId",
          label: "Person",
          required: true,
          options: [
            { value: "new", label: "+ A new person" },
            ...listPeople()
              .filter((p) => p.active && !taken.has(p.id))
              .map((p) => ({ value: p.id, label: p.name })),
          ],
        },
        {
          name: "name",
          label: "Full name (new person)",
          help: "Only needed when adding a new person.",
        },
        ...positionFields,
      ],
      schema: {
        ...positionSchema,
        personId: [rules.required()],
        name: [
          rules.custom((v, all) =>
            all.personId === "new" && !String(v ?? "").trim()
              ? "Enter the person’s name."
              : null,
          ),
        ],
      },
      async onSubmit(v) {
        const person =
          v.personId === "new"
            ? addPerson({ name: v.name })
            : getPerson(v.personId);
        upsertAssignment(person.id, edition.id, {
          position: v.position.trim(),
          unitId: v.unitId,
          visible: v.visible,
        });
        logAudit(admin, "Added committee member", person.name);
        notify.toast("success", "Member added");
        refreshMembers();
      },
    });
  });
}
