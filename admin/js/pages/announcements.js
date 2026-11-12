import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import { editionLabel } from "../services/edition.repo.js";
import {
  listAnnouncements,
  saveAnnouncement,
  deleteAnnouncement,
} from "../services/content.api.js";

export const title = "Announcements";
export const perm = "announcements.view";
export const crumbs = ["Content", "Announcements"];

/** UTC ISO <-> datetime-local in Lagos time (UTC+1, no daylight saving). */
const toInput = (iso) =>
  iso ? new Date(new Date(iso).getTime() + 36e5).toISOString().slice(0, 16) : "";
const fromInput = (v) => (v ? `${v}:00+01:00` : "");
const when = (iso) =>
  iso
    ? new Date(iso).toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      })
    : "—";

export default async function announcements({ edition, can }) {
  return html` <div class="oga-banner" role="note">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Messages for
        <strong>${editionLabel(edition)}</strong>. <em>Public</em> ones show on
        the home page and the news page. <em>Nominees only</em> ones appear
        only inside a signed-in nominee’s dashboard. Pinned messages come
        first.</span
      >
    </div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Announcements</h2>
        ${can("announcements.manage")
          ? html`<button class="btn btn-gold btn-sm" id="addBtn">
              <i class="fa-solid fa-plus me-1"></i>New announcement
            </button>`
          : ""}
      </div>
      <div id="annTable"></div>
    </div>`;
}

export async function init(root, { edition, can }) {
  let rows = await listAnnouncements(edition.id);
  const show = (a) => ({
    ...a,
    state: a.live ? "live" : a.status === "draft" ? "draft" : "hidden",
    who: a.audience === "nominees" ? "Nominees only" : "Public",
  });
  const table = new DataTable(root.querySelector("#annTable"), {
    can,
    rows: rows.map(show),
    searchKeys: ["title", "body"],
    columns: [
      {
        key: "title",
        label: "Announcement",
        sort: true,
        render: (a) =>
          html`<div>
            <strong>${a.pinned ? "📌 " : ""}${a.title}</strong>
            <small class="d-block text-secondary"
              >${a.body.length > 90 ? `${a.body.slice(0, 90)}…` : a.body}</small
            >
          </div>`,
      },
      { key: "who", label: "For", sort: true },
      {
        key: "state",
        label: "Status",
        sort: true,
        render: (a) => statusBadge(a.state),
      },
      {
        key: "publishAt",
        label: "Shows from",
        render: (a) => when(a.publishAt ?? a.createdAt),
      },
      { key: "expiresAt", label: "Hides after", render: (a) => when(a.expiresAt) },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "announcements.manage" },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "announcements.manage",
      },
    ],
    empty: "No announcements yet. Write the first one.",
    onAction: (key, a) => handlers[key](a),
  });
  const refresh = async () => {
    rows = await listAnnouncements(edition.id);
    table.setRows(rows.map(show));
  };

  const fields = [
    { name: "title", label: "Title", required: true },
    {
      name: "body",
      label: "Message",
      type: "textarea",
      rows: 6,
      required: true,
      help: "Plain text, up to 2000 characters. Line breaks are kept.",
    },
    {
      name: "audience",
      label: "Who sees it",
      col: "col-md-6",
      options: [
        { value: "public", label: "Everyone (public site)" },
        { value: "nominees", label: "Signed-in nominees only" },
      ],
    },
    {
      name: "status",
      label: "Status",
      col: "col-md-6",
      options: [
        { value: "published", label: "Published" },
        { value: "draft", label: "Draft (hidden)" },
      ],
    },
    {
      name: "publishAt",
      label: "Show from (optional)",
      type: "datetime-local",
      col: "col-md-6",
      help: "Lagos time. Leave empty to show it as soon as it is published.",
    },
    {
      name: "expiresAt",
      label: "Hide after (optional)",
      type: "datetime-local",
      col: "col-md-6",
      help: "Lagos time. Leave empty to keep it up.",
    },
    { type: "switch", name: "pinned", label: "Pin to the top" },
  ];
  const schema = { title: [rules.required(), rules.min(3), rules.max(150)], body: [rules.required(), rules.max(2000)] };
  const payload = (v) => ({
    title: v.title.trim(),
    body: v.body.trim(),
    audience: v.audience,
    status: v.status,
    pinned: Boolean(v.pinned),
    publishAt: fromInput(v.publishAt),
    expiresAt: fromInput(v.expiresAt),
  });

  const open = (a) =>
    openFormModal({
      title: a ? "Edit announcement" : "New announcement",
      fields,
      values: a
        ? { ...a, publishAt: toInput(a.publishAt), expiresAt: toInput(a.expiresAt) }
        : { audience: "public", status: "published", pinned: false },
      schema,
      async onSubmit(v) {
        await saveAnnouncement(edition.id, a?.id, payload(v));
        notify.toast("success", a ? "Announcement updated" : "Announcement saved");
        await refresh();
      },
    });
  const handlers = {
    edit: (a) => open(a),
    async delete(a) {
      if (
        !(await notify.confirm({
          title: `Delete “${a.title}”?`,
          text: "It disappears from the site at once.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      await deleteAnnouncement(edition.id, a.id);
      notify.toast("info", "Announcement deleted");
      await refresh();
    },
  };
  root.querySelector("#addBtn")?.addEventListener("click", () => open(null));
}