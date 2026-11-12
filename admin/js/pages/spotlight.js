import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import { editionLabel } from "../services/edition.repo.js";
import { listNominees } from "../services/nominee.repo.js";
import {
  listFaces,
  saveFace,
  featureFace,
  deleteFace,
} from "../services/content.api.js";

export const title = "Face of the period";
export const perm = "spotlight.view";
export const crumbs = ["Content", "Face of the period"];

export default async function spotlight({ edition, can }) {
  return html` <div class="oga-banner" role="note">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Put one approved nominee of
        <strong>${editionLabel(edition)}</strong> in the spotlight for a period
        you name (a month, a week…). The <em>featured</em> one is shown on the
        home page; earlier ones stay on the Face of the period page. Vote counts
        are never shown.</span
      >
    </div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Face of the period</h2>
        ${can("spotlight.manage")
          ? html`<button class="btn btn-gold btn-sm" id="addBtn">
              <i class="fa-solid fa-plus me-1"></i>Choose a face
            </button>`
          : ""}
      </div>
      <div id="faceTable"></div>
    </div>`;
}

export async function init(root, { edition, can }) {
  const [initial, nominees] = await Promise.all([listFaces(edition.id), listNominees()]);
  let rows = initial;
  const approved = nominees.filter((n) => n.status === "approved");
  const show = (f) => ({ ...f, state: f.featured ? "live" : "hidden" });
  const table = new DataTable(root.querySelector("#faceTable"), {
    can,
    rows: rows.map(show),
    searchKeys: ["name", "period", "headline"],
    columns: [
      {
        key: "name",
        label: "Nominee",
        sort: true,
        render: (f) =>
          html`<div class="oga-person">
            ${f.thumb || f.photo
              ? html`<img src="${mediaUrl(f.thumb || f.photo)}" alt="" width="40" height="40" style="border-radius:50%;object-fit:cover" />`
              : ""}
            <div><strong>${f.name}</strong><small>${f.category}</small></div>
          </div>`,
      },
      { key: "period", label: "Period", sort: true },
      { key: "headline", label: "Headline", render: (f) => f.headline || "—" },
      {
        key: "state",
        label: "On the home page",
        render: (f) => statusBadge(f.state, f.featured ? "Featured" : "Earlier"),
      },
    ],
    actions: [
      {
        key: "feature",
        label: "Feature on the home page",
        icon: "fa-star",
        perm: "spotlight.manage",
        when: (f) => !f.featured,
      },
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "spotlight.manage" },
      { key: "delete", label: "Remove", icon: "fa-trash", perm: "spotlight.manage" },
    ],
    empty: "No face chosen yet. Pick an approved nominee for the current period.",
    onAction: (key, f) => handlers[key](f),
  });
  const refresh = async () => {
    rows = await listFaces(edition.id);
    table.setRows(rows.map(show));
  };

  const fields = () => [
    {
      name: "nomineeId",
      label: "Nominee",
      required: true,
      options: approved.map((n) => ({ value: n.id, label: n.name })),
    },
    { name: "period", label: "Period", required: true, help: "For example “November 2026” or “Week 3”. Each period can have one face." },
    { name: "headline", label: "Headline (optional)", help: "Up to 120 characters, e.g. Face of the month." },
    { name: "note", label: "A few words about them (optional)", type: "textarea", rows: 3, help: "Up to 500 characters." },
    { type: "switch", name: "featured", label: "Feature on the home page now" },
  ];
  const schema = {
    nomineeId: [rules.required("Choose a nominee.")],
    period: [rules.required(), rules.min(2), rules.max(60)],
    headline: [rules.max(120)],
    note: [rules.max(500)],
  };
  const open = (f) =>
    openFormModal({
      title: f ? "Edit Face of the period" : "Choose a Face of the period",
      fields: fields(),
      values: f ? { ...f } : { featured: true },
      schema,
      async onSubmit(v) {
        await saveFace(edition.id, f?.id, {
          nomineeId: v.nomineeId,
          period: v.period.trim(),
          headline: (v.headline ?? "").trim(),
          note: (v.note ?? "").trim(),
          featured: Boolean(v.featured),
        });
        notify.toast("success", "Saved");
        await refresh();
      },
    });
  const handlers = {
    edit: (f) => open(f),
    async feature(f) {
      await featureFace(f.id);
      notify.toast("success", `${f.name} is now featured`);
      await refresh();
    },
    async delete(f) {
      if (
        !(await notify.confirm({
          title: `Remove ${f.name} (${f.period})?`,
          text: "They are taken off the public site. The nominee is not affected.",
          confirmText: "Remove",
          danger: true,
        }))
      )
        return;
      await deleteFace(edition.id, f.id);
      notify.toast("info", "Removed");
      await refresh();
    },
  };
  root.querySelector("#addBtn")?.addEventListener("click", () => open(null));
}