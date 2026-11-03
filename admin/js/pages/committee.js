import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { getResource } from "../../../shared/js/core/http.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import { COMMITTEE_STAGES } from "../../../shared/js/services/committee-recruitment.service.js";
import {
  loadCommittee,
  getRecruitmentConfig,
  listCommitteeApplications,
  listCommitteeMembers,
  listAddablePeople,
  saveRecruitmentConfig,
  applicationAction,
  addCommitteeMember,
  editCommitteeMember,
  removeCommitteeMember,
} from "../services/committee.api.js";

export const title = "Committee";
export const perm = "committee.view";
export const crumbs = ["People", "Committee"];

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

export async function init(root, { edition, can }) {
  // Everything below reads from what the server returned; every change goes to the server and reloads it.
  await loadCommittee(edition.id);

  const units = (await getResource("committee")).units;
  const unitName = (id) => units.find((u) => u.id === id)?.name ?? "—";
  const unitOptions = units.map((u) => ({ value: u.id, label: u.name }));
  const when = (iso) => (iso ? new Date(iso).toLocaleString() : "");
  const fail = (e) => notify.failure(e);

  const members = () =>
    listCommitteeMembers().map((m) => ({
      ...m,
      unitName: unitName(m.unitId),
      visibleKey: m.visible ? "live" : "hidden",
    }));
  const apps = () =>
    listCommitteeApplications().map((a) => ({
      ...a,
      unitName: unitName(a.unitId),
    }));

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
        key: "hasAccess",
        label: "Admin access",
        render: (m) =>
          m.hasAccess
            ? html`<span class="oga-chip">Has access</span>`
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

  const renderRecruitmentSummary = () => {
    const el = root.querySelector("#recruitmentSummary");
    if (!el) return;
    const rows = apps();
    const count = (status) => rows.filter((a) => a.status === status).length;
    const target = Number(getRecruitmentConfig().targetApplicants || 100);
    const card = (label, value) => `
      <div class="col-6 col-lg-3">
        <div class="card card-body">
          <small class="text-secondary">${label}</small>
          <strong class="fs-4">${value}</strong>
        </div>
      </div>`;
    el.innerHTML = `<div class="row g-3 mb-3">
      ${card("Applications", `${rows.filter((a) => a.status !== "withdrawn").length} / ${target}`)}
      ${card("Shortlisted", count("shortlisted"))}
      ${card("Approved", count("approved") + count("appointed"))}
      ${card("Reserve", count("reserve") + count("reconsideration"))}
    </div>`;
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
        key: "appoint",
        label: "Appoint to committee",
        icon: "fa-user-tie",
        variant: "gold",
        perm: "committee.review",
        when: (a) => a.status === "approved",
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

  const refreshAll = () => {
    membersTable.setRows(members());
    appsTable.setRows(apps());
    renderRecruitmentSummary();
  };
  renderRecruitmentSummary();

  /* ---- Recruitment settings */
  root
    .querySelector("#recruitmentSettingsBtn")
    ?.addEventListener("click", () => {
      const c = getRecruitmentConfig();
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
          },
          {
            name: "closesAt",
            label: "Applications close",
            type: "datetime-local",
          },
          {
            name: "targetApplicants",
            label: "Expected applicant target",
            type: "number",
          },
          { name: "screeningDate", label: "Screening date", type: "date" },
          { name: "screeningTime", label: "Screening time", type: "time" },
          { name: "screeningVenue", label: "Screening venue" },
          {
            name: "screeningInstructions",
            label: "Screening instructions",
            type: "textarea",
          },
          {
            name: "approvalMessage",
            label: "Approval message",
            type: "textarea",
          },
          {
            name: "onboardingMessage",
            label: "Onboarding instructions",
            type: "textarea",
          },
          {
            name: "unsuccessfulMessage",
            label: "Unsuccessful application message",
            type: "textarea",
          },
          {
            name: "reserveMessage",
            label: "Reserve list message",
            type: "textarea",
          },
          {
            name: "whatsappGroupUrl",
            label: "Committee WhatsApp group link",
            type: "url",
          },
          {
            type: "switch",
            name: "photoUploadsEnabled",
            label: "Allow applicant photo uploads",
          },
          {
            name: "photoMaxSizeKB",
            label: "Maximum photo size (KB, up to 5120)",
            type: "number",
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
          await saveRecruitmentConfig(edition.id, {
            ...v,
            enabled: Boolean(v.enabled),
            photoUploadsEnabled: Boolean(v.photoUploadsEnabled),
            targetApplicants: Number(v.targetApplicants),
            photoMaxSizeKB: Number(v.photoMaxSizeKB),
          });
          notify.toast("success", "Recruitment settings saved.");
          renderRecruitmentSummary();
        },
      });
    });

  /* ---- Committee members */
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
          await editCommitteeMember(edition.id, m.id, {
            position: v.position.trim(),
            unitId: v.unitId,
            visible: Boolean(v.visible),
          });
          notify.toast("success", "Member updated");
          refreshAll();
        },
      }),
    async remove(m) {
      if (
        !(await notify.confirm({
          title: `Remove ${m.name}?`,
          text: m.hasAccess
            ? "They leave the committee list but keep their admin access."
            : "They’ll disappear from this edition’s committee.",
          confirmText: "Remove",
          danger: true,
        }))
      )
        return;
      try {
        await removeCommitteeMember(edition.id, m.id);
        notify.toast("success", "Removed");
        refreshAll();
      } catch (e) {
        fail(e);
      }
    },
  };

  /* ---- Applications: every stage change is made (and checked) on the server */
  const act = async (a, action, fields, message) => {
    await applicationAction(edition.id, a.id, action, fields);
    notify.toast("success", message);
    refreshAll();
  };

  const appHandlers = {
    view: (a) =>
      openInfoModal({
        title: a.name,
        body: html`
          ${a.photo
            ? html`<img
                src="${mediaUrl(a.thumb || a.photo)}"
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
              <dd>
                ${a.screeningResult ?? "Not recorded"}${a.screeningNote
                  ? ` — ${a.screeningNote}`
                  : ""}
              </dd>
            </div>
          </dl>
          <h3 class="h6 mt-3">Motivation</h3>
          <p>${a.motivation || "No motivation statement supplied."}</p>
          <h3 class="h6 mt-3">Application history</h3>
          <ul>
            ${(a.history ?? []).map(
              (item) => html`
                <li>
                  ${COMMITTEE_STAGES[item.stage] ?? item.stage} —
                  ${when(item.at)}
                  (${item.actor})${item.note ? `: ${item.note}` : ""}
                </li>
              `,
            )}
          </ul>
        `,
      }),

    shortlist: (a) =>
      openFormModal({
        title: `Shortlist ${a.name}`,
        submitLabel: "Shortlist and email the applicant",
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
          await act(
            a,
            "shortlist",
            { note: v.note },
            "Applicant shortlisted; email queued.",
          );
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
          await act(
            a,
            "screen",
            { result: v.result, note: v.note },
            "Screening result saved.",
          );
        },
      }),

    approve: (a) => {
      const c = getRecruitmentConfig();
      openFormModal({
        title: `Approve ${a.name}`,
        submitLabel: "Approve and email the applicant",
        fields: [
          {
            name: "message",
            label: "Approval message",
            type: "textarea",
            value: c.approvalMessage,
          },
          {
            name: "onboarding",
            label: "Onboarding instructions",
            type: "textarea",
            value: c.onboardingMessage,
          },
        ],
        async onSubmit(v) {
          await act(
            a,
            "approve",
            { message: v.message, onboarding: v.onboarding },
            "Applicant approved; email queued.",
          );
        },
      });
    },

    appoint: (a) =>
      openFormModal({
        title: `Appoint ${a.name}`,
        submitLabel: "Appoint to committee",
        fields: [
          { name: "position", label: "Committee position", required: true },
          {
            name: "unitId",
            label: "Committee unit",
            required: true,
            options: unitOptions,
          },
          {
            type: "switch",
            name: "visible",
            label: "Show on the public Committee page",
          },
        ],
        values: { position: "", unitId: a.unitId || "", visible: true },
        schema: {
          position: [rules.required(), rules.max(60)],
          unitId: [rules.required("Choose a committee unit.")],
        },
        async onSubmit(v) {
          await act(
            a,
            "appoint",
            {
              position: v.position.trim(),
              unitId: v.unitId,
              visible: Boolean(v.visible),
            },
            "Applicant appointed; email queued.",
          );
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
            value: getRecruitmentConfig().reserveMessage,
          },
        ],
        async onSubmit(v) {
          await act(
            a,
            "reserve",
            { note: v.note },
            "Moved to the reserve list; email queued.",
          );
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
          await act(
            a,
            "reconsider",
            { note: v.note },
            "Applicant is being reconsidered.",
          );
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
          await act(
            a,
            "withdraw",
            { note: v.note },
            "Withdrawal recorded. You can now consider reserve applicants.",
          );
        },
      }),
  };

  root.querySelector("#addMember")?.addEventListener("click", () =>
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
            ...listAddablePeople().map((p) => ({ value: p.id, label: p.name })),
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
        await addCommitteeMember(edition.id, {
          personId: v.personId,
          name: v.name,
          position: v.position.trim(),
          unitId: v.unitId,
          visible: Boolean(v.visible),
        });
        notify.toast("success", "Member added");
        refreshAll();
      },
    }),
  );
}
