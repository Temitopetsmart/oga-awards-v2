import { html } from "../../../shared/js/core/dom.js";
import { number, formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store } from "../../../shared/js/core/store.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { logAudit } from "../services/admin-store.js";
import { listNominees } from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import { scoped } from "../services/scope.js";
import {
  listEditions,
  updateEdition,
  transition,
  createEdition,
  deleteDraftEdition,
  setViewing,
  editionLabel,
} from "../services/edition.repo.js";
import { accessibleEditionIds } from "../services/team.repo.js";

export const title = "Editions";
export const perm = "editions.view";
export const crumbs = ["Administration", "Editions"];

const ACTIONS = {
  draft: [["activate", "Activate", "fa-play", "active"]],
  active: [["complete", "Mark completed", "fa-flag-checkered", "completed"]],
  completed: [
    ["reopen", "Reopen as active", "fa-rotate-left", "active"],
    ["archive", "Archive", "fa-box-archive", "archived"],
  ],
  archived: [["unarchive", "Unarchive", "fa-box-open", "completed"]],
};
const CONFIRM = {
  activate: (e) => ({
    title: `Activate ${editionLabel(e)}?`,
    text: "The public site will start following this edition. Only one edition can be active at a time.",
    confirmText: "Activate",
  }),
  complete: (e) => ({
    title: `Mark ${editionLabel(e)} as completed?`,
    text: "Its records become read-only and the public site will show the Hall of Fame until you activate another edition. Make sure results are saved first.",
    confirmText: "Mark completed",
    danger: true,
  }),
  reopen: (e) => ({
    title: `Reopen ${editionLabel(e)}?`,
    text: "It becomes the active edition again and its records can be changed.",
    confirmText: "Reopen",
  }),
  archive: (e) => ({
    title: `Archive ${editionLabel(e)}?`,
    text: "It stays available for reference, read-only, and moves out of the way.",
    confirmText: "Archive",
  }),
  unarchive: (e) => ({
    title: `Unarchive ${editionLabel(e)}?`,
    text: "It goes back to completed.",
    confirmText: "Unarchive",
  }),
};

export default async function editions({ can, access }) {
  const ids = accessibleEditionIds(access.person, listEditions());
  const list = listEditions().filter((e) => ids.includes(e.id));
  const rows = await Promise.all(
    list.map(async (e) => ({
      e,
      nominees: (await listNominees(e.id)).length,
      categories: (await listCategories(e.id)).length,
      votes: scoped(store.get("vote-orders", []), e.id)
        .filter((o) => o.status === "paid")
        .reduce((s, o) => s + o.votes, 0),
      tickets: scoped(store.get("tickets", []), e.id).length,
    })),
  );
  const manage = can("editions.manage");

  return html` <div
      class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"
    >
      <p class="mb-0 text-secondary">
        Each edition keeps its own nominees, categories, votes, results,
        tickets, committee and settings. Only one can be active at a time.
      </p>
      ${manage
        ? html`<button class="btn btn-gold" id="newBtn">
            <i class="fa-solid fa-plus me-1"></i>Create edition
          </button>`
        : ""}
    </div>
    <ol class="oga-edtl" id="edList">
      ${rows.map(
        ({ e, nominees, categories, votes, tickets }) =>
          html` <li
            class="${e.status === "active" ? "is-active" : ""} ${e.id ===
            access.edition.id
              ? "is-viewing"
              : ""}"
            data-id="${e.id}"
          >
            <div class="oga-edcard">
              <div
                class="d-flex flex-wrap justify-content-between align-items-start gap-2"
              >
                <div>
                  <h2 class="h5 mb-1">
                    ${editionLabel(e)}
                    <small class="text-secondary">${e.year ?? ""}</small>
                  </h2>
                  <div>
                    ${statusBadge(e.status)}
                    ${e.id === access.edition.id
                      ? html`<span class="oga-status oga-status--muted ms-1"
                          >Viewing</span
                        >`
                      : ""}
                    ${e.timeline?.ceremony
                      ? html`<small class="text-secondary ms-2"
                          >Ceremony
                          ${formatDate(e.timeline.ceremony, {
                            dateStyle: "medium",
                          })}</small
                        >`
                      : ""}
                  </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  ${e.id !== access.edition.id
                    ? html`<button
                        class="btn btn-ghost btn-sm"
                        data-act="view"
                        data-id="${e.id}"
                      >
                        <i class="fa-solid fa-eye me-1"></i>View records
                      </button>`
                    : ""}
                  ${manage
                    ? html`${(ACTIONS[e.status] ?? []).map(
                          ([key, label, icon]) =>
                            html`<button
                              class="btn ${key === "activate"
                                ? "btn-gold"
                                : "btn-ghost"} btn-sm"
                              data-act="${key}"
                              data-id="${e.id}"
                            >
                              <i class="fa-solid ${icon} me-1"></i>${label}
                            </button>`,
                        )}
                        <button
                          class="btn btn-ghost btn-sm"
                          data-act="edit"
                          data-id="${e.id}"
                        >
                          <i class="fa-solid fa-pen me-1"></i>Edit
                        </button>
                        ${e.status === "draft"
                          ? html`<button
                              class="btn btn-ghost btn-sm"
                              data-act="delete"
                              data-id="${e.id}"
                            >
                              <i class="fa-solid fa-trash me-1"></i>Delete
                            </button>`
                          : ""}`
                    : ""}
                </div>
              </div>
              <div class="oga-edcard__stats">
                <span><strong>${number(nominees)}</strong> nominees</span
                ><span><strong>${number(categories)}</strong> categories</span>
                <span><strong>${number(votes)}</strong> paid votes</span
                ><span><strong>${number(tickets)}</strong> tickets</span>
              </div>
            </div>
          </li>`,
      )}
    </ol>`;
}

export function init(root, { admin, can }) {
  const manage = can("editions.manage");
  const find = (id) => listEditions().find((e) => e.id === id);

  root.querySelector("#edList").addEventListener("click", async (ev) => {
    const btn = ev.target.closest("[data-act]");
    if (!btn) return;
    const e = find(btn.dataset.id);
    const act = btn.dataset.act;
    if (act === "view") {
      setViewing(e.id);
      location.assign(`${ROOT}admin/index.html`);
      return;
    }
    if (!manage) return;
    if (act === "edit") {
      openFormModal({
        title: `Edit ${editionLabel(e)}`,
        values: e,
        fields: [
          { name: "name", label: "Name", required: true },
          { name: "edition", label: "Edition number", required: true },
          { name: "year", label: "Year", type: "number", required: true },
          { name: "tagline", label: "Tagline" },
        ],
        schema: {
          name: [rules.required()],
          edition: [rules.required()],
          year: [
            rules.required(),
            rules.custom((v) =>
              Number.isInteger(Number(v)) &&
              Number(v) >= 2000 &&
              Number(v) <= 2100
                ? null
                : "Enter a year like 2027.",
            ),
          ],
        },
        async onSubmit(v) {
          updateEdition(e.id, (x) =>
            Object.assign(x, {
              name: v.name.trim(),
              edition: v.edition.trim(),
              year: Number(v.year),
              tagline: v.tagline.trim(),
            }),
          );
          logAudit(admin, "Edited edition", `${v.name} ${v.edition}`);
          location.reload();
        },
      });
      return;
    }
    if (act === "delete") {
      if (
        !(await notify.confirm({
          title: `Delete draft ${editionLabel(e)}?`,
          text: "Its categories, ticket types and team assignments are removed too. This can’t be undone.",
          confirmText: "Delete draft",
          danger: true,
        }))
      )
        return;
      const r = deleteDraftEdition(e.id);
      if (!r.ok) {
        await notify.warning("Can’t delete", r.error);
        return;
      }
      logAudit(admin, "Deleted draft edition", editionLabel(e));
      location.reload();
      return;
    }
    const to = ACTIONS[e.status].find(([key]) => key === act)[3];
    if (!(await notify.confirm(CONFIRM[act](e)))) return;
    const r = transition(e.id, to);
    if (!r.ok) {
      await notify.warning("Can’t do that yet", r.error);
      return;
    }
    logAudit(
      admin,
      `Edition ${to === "active" ? "activated" : to}`,
      editionLabel(e),
      e.id,
    );
    location.reload();
  });

  root.querySelector("#newBtn")?.addEventListener("click", () => {
    const existing = listEditions();
    openFormModal({
      title: "Create a new edition",
      submitLabel: "Create draft edition",
      values: {
        name: "OGA",
        year: new Date().getFullYear() + 1,
        copyFrom: existing[0]?.id ?? "",
        copy_categories: true,
        copy_ticketTypes: true,
        copy_settings: true,
        copy_team: false,
        copy_occasions: false,
      },
      fields: [
        { name: "name", label: "Name", required: true, col: "" },
        {
          name: "edition",
          label: "Edition number",
          required: true,
          placeholder: "e.g. 11.0",
        },
        { name: "year", label: "Year", type: "number", required: true },
        { name: "tagline", label: "Tagline (optional)" },
        {
          name: "copyFrom",
          label: "Start from",
          placeholder: "Start empty",
          options: existing.map((e) => ({
            value: e.id,
            label: editionLabel(e),
          })),
          help: "Copies structure only. Nominees, votes, tickets, payments and results are never copied.",
        },
        { type: "switch", name: "copy_categories", label: "Copy categories" },
        {
          type: "switch",
          name: "copy_ticketTypes",
          label: "Copy ticket types",
        },
        {
          type: "switch",
          name: "copy_settings",
          label: "Copy settings (features and voting rules)",
        },
        {
          type: "switch",
          name: "copy_occasions",
          label: "Copy special occasions (hidden)",
        },
        {
          type: "switch",
          name: "copy_team",
          label: "Copy team assignments (people and roles)",
        },
      ],
      schema: {
        name: [rules.required(), rules.max(30)],
        edition: [
          rules.required(),
          rules.max(10),
          rules.custom((v, all) =>
            existing.some(
              (e) =>
                e.name.toLowerCase() === all.name.trim().toLowerCase() &&
                e.edition === v.trim(),
            )
              ? "That edition already exists."
              : null,
          ),
        ],
        year: [
          rules.required(),
          rules.custom((v) =>
            Number.isInteger(Number(v)) &&
            Number(v) >= 2000 &&
            Number(v) <= 2100
              ? null
              : "Enter a year like 2028.",
          ),
        ],
      },
      async onSubmit(v) {
        const e = await createEdition({
          name: v.name.trim(),
          edition: v.edition.trim(),
          year: v.year,
          tagline: v.tagline.trim(),
          copyFrom: v.copyFrom || null,
          copy: {
            categories: v.copy_categories,
            ticketTypes: v.copy_ticketTypes,
            settings: v.copy_settings,
            occasions: v.copy_occasions,
            team: v.copy_team,
          },
        });
        logAudit(admin, "Created edition", editionLabel(e), e.id);
        notify.toast("success", `${editionLabel(e)} created as a draft`);
        location.reload();
      },
    });
  });
}
