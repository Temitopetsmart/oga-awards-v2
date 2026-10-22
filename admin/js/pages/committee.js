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
import {
  COMMITTEE_STAGES,
  listCommitteeApplications,
  updateCommitteeApplication,
  getRecruitmentConfig,
  saveRecruitmentConfig,
  queueCommitteeNotification,
} from "../../../shared/js/services/committee-recruitment.service.js";

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
        <div
          class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3"
        >
          <div>
            <h2 class="h5 mb-1">Recruitment applications</h2>
            <p class="text-secondary mb-0">
              Shortlist applicants, schedule screening, record decisions and
              manage reserves.
            </p>
          </div>
          ${can("committee.review")
            ? html`
                <button class="btn btn-gold btn-sm" id="recruitmentSettingsBtn">
                  <i class="fa-solid fa-calendar-days me-1"></i>
                  Recruitment settings
                </button>
              `
            : ""}
        </div>
        <div id="recruitmentSummary" class="px-3"></div>
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
    listCommitteeApplications(edition.id).map((application) => ({
      ...application,
      status: application.status ?? "submitted",
      unitName: unitName(application.unitId),
    }));

  const patchApp = (application, patch, note = "") =>
    updateCommitteeApplication(
      application.id,
      edition.id,
      { ...patch, historyNote: note },
      admin?.name ?? "Admin",
    );

  const queueNotice = (application, type, details = {}) =>
    queueCommitteeNotification(application, type, details);

  const renderRecruitmentSummary = () => {
    const config = getRecruitmentConfig(edition.id);
    const rows = apps();
    const count = (status) => rows.filter((a) => a.status === status).length;
    const target = Number(config.targetApplicants || 100);

    const el = root.querySelector("#recruitmentSummary");
    if (!el) return;

    el.innerHTML = `
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-3">
        <div class="card card-body">
          <small class="text-secondary">Applications</small>
          <strong class="fs-4">${rows.length} / ${target}</strong>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card card-body">
          <small class="text-secondary">Shortlisted</small>
          <strong class="fs-4">${count("shortlisted")}</strong>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card card-body">
          <small class="text-secondary">Approved</small>
          <strong class="fs-4">${count("approved") + count("appointed")}</strong>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card card-body">
          <small class="text-secondary">Reserve</small>
          <strong class="fs-4">${count("reserve") + count("reconsideration")}</strong>
        </div>
      </div>
    </div>
  `;
  };
  const appsTable = new DataTable(root.querySelector("#appsTable"), {
    can,
    rows: apps(),
    searchKeys: ["name", "email", "department", "unitName"],

    filters: [
      {
        key: "status",
        label: "Stage",
        options: Object.entries(COMMITTEE_STAGES).map(([value, label]) => ({
          value,
          label,
        })),
      },
    ],

    columns: [
      {
        key: "name",
        label: "Applicant",
        sort: true,
        render: (a) => html`
          <div class="oga-person">
            ${avatar(a)}
            <div>
              <strong>${a.name}</strong>
              <small class="d-block text-secondary">${a.email}</small>
            </div>
          </div>
        `,
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
        label: "Recruitment stage",
        sort: true,
        render: (a) => html`
          <span class="badge text-bg-secondary">
            ${COMMITTEE_STAGES[a.status] ?? a.status}
          </span>
        `,
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
        key: "shortlist",
        label: "Shortlist for screening",
        icon: "fa-user-check",
        perm: "committee.review",
        when: (a) =>
          ["submitted", "reserve", "reconsideration"].includes(a.status),
      },
      {
        key: "screen",
        label: "Record screening result",
        icon: "fa-clipboard-check",
        perm: "committee.review",
        when: (a) => a.status === "shortlisted",
      },
      {
        key: "approve",
        label: "Approve applicant",
        icon: "fa-circle-check",
        variant: "gold",
        perm: "committee.review",
        when: (a) => a.status === "screened" && a.screeningResult === "passed",
      },
      {
        key: "reserve",
        label: "Move to reserve list",
        icon: "fa-clock",
        perm: "committee.review",
        when: (a) => ["screened", "unsuccessful"].includes(a.status),
      },
      {
        key: "reconsider",
        label: "Reconsider applicant",
        icon: "fa-rotate-left",
        perm: "committee.review",
        when: (a) => ["reserve", "unsuccessful"].includes(a.status),
      },
      {
        key: "withdraw",
        label: "Record withdrawal",
        icon: "fa-user-minus",
        perm: "committee.review",
        when: (a) => ["approved", "appointed"].includes(a.status),
      },
    ],

    empty:
      "No applications yet in this edition. They arrive from the Committee page on the public site.",
    onAction: (key, a) => appHandlers[key](a),
  });

  const refreshMembers = () => membersTable.setRows(members());
  const refreshApps = () => appsTable.setRows(apps());
  renderRecruitmentSummary();

  root
    .querySelector("#recruitmentSettingsBtn")
    ?.addEventListener("click", () => {
      const c = getRecruitmentConfig(edition.id);

      openFormModal({
        title: "Recruitment settings",
        submitLabel: "Save recruitment settings",
        fields: [
          {
            type: "switch",
            name: "enabled",
            label: "Enable recruitment applications",
          },
          {
            name: "opensAt",
            label: "Applications open",
            type: "datetime-local",
            value: c.opensAt,
          },
          {
            name: "closesAt",
            label: "Applications close",
            type: "datetime-local",
            value: c.closesAt,
          },
          {
            name: "targetApplicants",
            label: "Expected applicant target",
            type: "number",
            value: c.targetApplicants,
          },
          {
            name: "screeningDate",
            label: "Screening date",
            type: "date",
            value: c.screeningDate,
          },
          {
            name: "screeningTime",
            label: "Screening time",
            type: "time",
            value: c.screeningTime,
          },
          {
            name: "screeningVenue",
            label: "Screening venue",
            value: c.screeningVenue,
          },
          {
            name: "screeningInstructions",
            label: "Screening instructions",
            type: "textarea",
            value: c.screeningInstructions,
          },
        ],
        values: c,
        schema: {
          opensAt: [rules.required("Set the opening date and time.")],
          closesAt: [rules.required("Set the closing date and time.")],
          targetApplicants: [
            rules.custom((v) =>
              Number.isInteger(Number(v)) && Number(v) >= 1
                ? null
                : "Enter an applicant target of at least 1.",
            ),
          ],
        },
        async onSubmit(v) {
          saveRecruitmentConfig(edition.id, {
            ...v,
            enabled: Boolean(v.enabled),
            targetApplicants: Number(v.targetApplicants),
          });

          logAudit(admin, "Updated committee recruitment settings", edition.id);
          notify.toast("success", "Recruitment settings saved.");
          renderRecruitmentSummary();
        },
      });
    });

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
        body: html`
          ${a.photo
            ? html`<img
                src="${a.photo}"
                alt="${a.name}"
                class="rounded mb-3"
                style="width:120px;height:120px;object-fit:cover"
              />`
            : ""}
          <dl class="oga-dl">
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
              <dd>${unitName(a.unitId)}</dd>
            </div>
            <div>
              <dt>Stage</dt>
              <dd>${COMMITTEE_STAGES[a.status] ?? a.status}</dd>
            </div>
            <div>
              <dt>Screening result</dt>
              <dd>${a.screeningResult ?? "Not recorded"}</dd>
            </div>
          </dl>
          <h3 class="h6 mt-3">Motivation</h3>
          <p>${a.motivation || "No motivation statement supplied."}</p>
          <h3 class="h6 mt-3">Application history</h3>
          <ul>
            ${(a.history ?? []).map(
              (item) => html`
                <li>
                  ${COMMITTEE_STAGES[item.stage] ?? item.stage} — ${item.at}
                  ${item.note ? `: ${item.note}` : ""}
                </li>
              `,
            )}
          </ul>
        `,
      }),

    shortlist: (a) =>
      openFormModal({
        title: `Shortlist ${a.name}`,
        submitLabel: "Shortlist and queue notification",
        fields: [
          {
            name: "note",
            label: "Message or note",
            type: "textarea",
            value:
              "You have been shortlisted for the committee screening exercise.",
          },
        ],
        async onSubmit(v) {
          const config = getRecruitmentConfig(edition.id);

          if (
            !config.screeningDate ||
            !config.screeningTime ||
            !config.screeningVenue
          ) {
            throw new Error(
              "Set the screening date, time and venue in Recruitment settings first.",
            );
          }

          patchApp(
            a,
            {
              status: "shortlisted",
              screening: {
                date: config.screeningDate,
                time: config.screeningTime,
                venue: config.screeningVenue,
                instructions: config.screeningInstructions,
              },
            },
            "Shortlisted for screening",
          );

          queueNotice(a, "shortlisted", {
            date: config.screeningDate,
            time: config.screeningTime,
            venue: config.screeningVenue,
            instructions: config.screeningInstructions,
            message: v.note,
          });

          logAudit(admin, "Shortlisted committee applicant", a.name);
          notify.toast(
            "success",
            "Applicant shortlisted; notification queued in demo.",
          );
          refreshApps();
          renderRecruitmentSummary();
        },
      }),

    screen: (a) =>
      openFormModal({
        title: `Screening result: ${a.name}`,
        submitLabel: "Save screening result",
        fields: [
          {
            name: "result",
            label: "Screening outcome",
            required: true,
            options: [
              { value: "passed", label: "Passed" },
              { value: "failed", label: "Did not pass" },
              { value: "pending", label: "Further review required" },
            ],
          },
          { name: "note", label: "Screening notes", type: "textarea" },
        ],
        schema: { result: [rules.required("Choose an outcome.")] },
        async onSubmit(v) {
          if (v.result === "pending") {
            patchApp(
              a,
              {
                status: "shortlisted",
                screeningResult: "pending",
                screeningNote: v.note,
              },
              "Screening requires further review",
            );
          } else if (v.result === "passed") {
            patchApp(
              a,
              {
                status: "screened",
                screeningResult: "passed",
                screeningNote: v.note,
              },
              "Screening passed",
            );
          } else {
            patchApp(
              a,
              {
                status: "unsuccessful",
                screeningResult: "failed",
                screeningNote: v.note,
              },
              "Did not pass screening",
            );

            queueNotice(a, "unsuccessful", {
              message: getRecruitmentConfig(edition.id).unsuccessfulMessage,
            });
          }

          logAudit(admin, "Recorded committee screening result", a.name);
          notify.toast("success", "Screening result saved.");
          refreshApps();
          renderRecruitmentSummary();
        },
      }),

    approve: (a) =>
      openFormModal({
        title: `Approve ${a.name}`,
        submitLabel: "Approve and queue notification",
        fields: [
          {
            name: "message",
            label: "Approval message",
            type: "textarea",
            value: getRecruitmentConfig(edition.id).approvalMessage,
          },
          {
            name: "onboarding",
            label: "Onboarding instructions",
            type: "textarea",
            value: getRecruitmentConfig(edition.id).onboardingMessage,
          },
        ],
        async onSubmit(v) {
          patchApp(
            a,
            {
              status: "approved",
              decision: "approved",
              decisionReason: v.message,
              onboardingMessage: v.onboarding,
            },
            "Approved after screening",
          );

          queueNotice(a, "approved", {
            message: v.message,
            onboarding: v.onboarding,
            whatsappGroupUrl: getRecruitmentConfig(edition.id).whatsappGroupUrl,
          });

          logAudit(admin, "Approved committee applicant", a.name);
          notify.toast("success", "Approval notification queued in demo.");
          refreshApps();
          renderRecruitmentSummary();
        },
      }),

    reserve: (a) =>
      openFormModal({
        title: `Move ${a.name} to reserve list`,
        submitLabel: "Save reserve decision",
        fields: [
          {
            name: "note",
            label: "Reserve message",
            type: "textarea",
            value: getRecruitmentConfig(edition.id).reserveMessage,
          },
        ],
        async onSubmit(v) {
          patchApp(
            a,
            {
              status: "reserve",
              decision: "reserve",
              decisionReason: v.note,
            },
            "Placed on reserve list",
          );

          queueNotice(a, "reserve", { message: v.note });
          logAudit(admin, "Placed committee applicant on reserve list", a.name);
          notify.toast("success", "Reserve notification queued in demo.");
          refreshApps();
          renderRecruitmentSummary();
        },
      }),

    reconsider: (a) =>
      openFormModal({
        title: `Reconsider ${a.name}`,
        submitLabel: "Reopen consideration",
        fields: [
          {
            name: "note",
            label: "Reason for reconsideration",
            type: "textarea",
            required: true,
          },
        ],
        schema: { note: [rules.required()] },
        async onSubmit(v) {
          patchApp(
            a,
            {
              status: "reconsideration",
              decisionReason: v.note,
              reconsiderationAt: new Date().toISOString(),
              interested: null,
            },
            "Reopened for reconsideration",
          );

          queueNotice(a, "reconsideration", { message: v.note });
          logAudit(admin, "Reopened committee application", a.name);
          notify.toast(
            "success",
            "Reconsideration notification queued in demo.",
          );
          refreshApps();
          renderRecruitmentSummary();
        },
      }),

    withdraw: (a) =>
      openFormModal({
        title: `Record withdrawal: ${a.name}`,
        submitLabel: "Confirm withdrawal",
        fields: [
          {
            name: "note",
            label: "Reason or notes",
            type: "textarea",
            required: true,
          },
        ],
        schema: { note: [rules.required()] },
        async onSubmit(v) {
          patchApp(
            a,
            {
              status: "withdrawn",
              decisionReason: v.note,
              withdrawnAt: new Date().toISOString(),
            },
            "Applicant withdrew or forfeited their position",
          );

          logAudit(admin, "Recorded committee withdrawal", a.name);
          notify.toast(
            "success",
            "Withdrawal recorded. You can now consider reserve applicants.",
          );
          refreshApps();
          renderRecruitmentSummary();
        },
      }),
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
