import { html, mount } from "../../../shared/js/core/dom.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
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
  getSpotlightSettings,
  saveSpotlightSettings,
  saveFace,
  featureFace,
  deleteFace,
} from "../services/content.api.js";

export const title = "Face of the period";
export const perm = "spotlight.view";
export const crumbs = ["Content", "Face of the period"];

const UNITS = [
  { value: "second", label: "seconds (5 or more)" },
  { value: "minute", label: "minutes" },
  { value: "hour", label: "hours" },
  { value: "day", label: "days" },
  { value: "week", label: "weeks" },
  { value: "month", label: "months" },
];

const sw = (name, label, on) =>
  html`<div class="form-check form-switch mb-2">
    <input
      class="form-check-input"
      type="checkbox"
      role="switch"
      id="f-${name}"
      name="${name}"
      ${on ? "checked" : ""}
    />
    <label class="form-check-label" for="f-${name}">${label}</label>
  </div>`;

export default async function spotlight({ edition, can }) {
  return html` <div id="liveCard"></div>
    <div class="oga-banner" role="note">
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

/** The live "Face of the moment": switch it on, pick how often it changes and who can appear. */
async function initLive(root, { edition, can }) {
  const { settings: s, eligible } = await getSpotlightSettings(edition.id);
  const editable = can("spotlight.manage");
  mount(
    root.querySelector("#liveCard"),
    html`<div class="card mb-4">
      <div
        class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"
      >
        <h2 class="card-title mb-0">Live Face of the moment</h2>
        <a
          class="btn btn-ghost btn-sm"
          href="../spotlight"
          target="_blank"
          rel="noopener"
          ><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>See it on
          the site</a
        >
      </div>
      <div class="card-body">
        <p class="small text-secondary">
          The public site shows one random person at a time, with their photo,
          name and bio, and changes to someone else by itself. Nobody can tell
          who is next. Everyone in the draw gets a turn before anyone comes
          round again. Right now
          <strong>${eligible.nominees}</strong> nominee${eligible.nominees === 1
            ? ""
            : "s"}
          and <strong>${eligible.committee}</strong> committee
          member${eligible.committee === 1 ? "" : "s"} can be picked. Vote
          counts are never shown.
        </p>
        <form id="liveForm" novalidate>
          <fieldset ${editable ? "" : "disabled"}>
            ${sw(
              "enabled",
              "Show the live Face of the moment on the site",
              s.enabled,
            )}
            <div class="row">
              ${field({
                label: "Change every",
                name: "every",
                type: "number",
                required: true,
                value: s.every,
                col: "col-md-3",
                help: "A whole number.",
              })}
              ${field({
                label: "Unit",
                name: "unit",
                required: true,
                value: s.unit,
                col: "col-md-3",
                options: UNITS,
              })}
              ${field({
                label: "Who can be shown",
                name: "who",
                required: true,
                value: s.who,
                col: "col-md-6",
                options: [
                  { value: "both", label: "Nominees and committee members" },
                  { value: "nominees", label: "Approved nominees only" },
                  { value: "committee", label: "Committee members only" },
                ],
              })}
              ${field({
                label: "Heading",
                name: "heading",
                required: true,
                value: s.heading,
                col: "col-md-6",
                help: "Use {period} for the time, for example: Meet the face of the {period}.",
              })}
              ${field({
                label: "Line under the timer",
                name: "tagline",
                value: s.tagline,
                col: "col-md-6",
                help: "Optional, up to 160 characters.",
              })}
            </div>
            ${sw("showBio", "Show the nominee’s bio", s.showBio)}
            ${sw(
              "requirePhoto",
              "Only people who have a photo (recommended: the photo is the main picture)",
              s.requirePhoto,
            )}
            ${editable
              ? html`<button class="btn btn-gold mt-3" type="submit">
                  Save
                </button>`
              : ""}
          </fieldset>
        </form>
      </div>
    </div>`,
  );
  bindForm(root.querySelector("#liveForm"), {
    schema: {
      every: [rules.required()],
      heading: [rules.required(), rules.max(80)],
      tagline: [rules.max(160)],
    },
    async onSubmit(v, form) {
      await saveSpotlightSettings(edition.id, {
        enabled: form.elements.enabled.checked,
        every: Number(v.every),
        unit: v.unit,
        who: v.who,
        heading: v.heading.trim(),
        tagline: (v.tagline ?? "").trim(),
        showBio: form.elements.showBio.checked,
        requirePhoto: form.elements.requirePhoto.checked,
      });
      notify.toast("success", "Saved");
    },
  });
}

export async function init(root, { edition, can }) {
  initLive(root, { edition, can }).catch((e) => notify.failure(e));
  const [initial, nominees] = await Promise.all([
    listFaces(edition.id),
    listNominees(),
  ]);
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
              ? html`<img
                  src="${mediaUrl(f.thumb || f.photo)}"
                  alt=""
                  width="40"
                  height="40"
                  style="border-radius:50%;object-fit:cover"
                />`
              : ""}
            <div><strong>${f.name}</strong><small>${f.category}</small></div>
          </div>`,
      },
      { key: "period", label: "Period", sort: true },
      { key: "headline", label: "Headline", render: (f) => f.headline || "—" },
      {
        key: "state",
        label: "On the home page",
        render: (f) =>
          statusBadge(f.state, f.featured ? "Featured" : "Earlier"),
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
      {
        key: "delete",
        label: "Remove",
        icon: "fa-trash",
        perm: "spotlight.manage",
      },
    ],
    empty:
      "No face chosen yet. Pick an approved nominee for the current period.",
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
    {
      name: "period",
      label: "Period",
      required: true,
      help: "For example “November 2026” or “Week 3”. Each period can have one face.",
    },
    {
      name: "headline",
      label: "Headline (optional)",
      help: "Up to 120 characters, e.g. Face of the month.",
    },
    {
      name: "note",
      label: "A few words about them (optional)",
      type: "textarea",
      rows: 3,
      help: "Up to 500 characters.",
    },
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
