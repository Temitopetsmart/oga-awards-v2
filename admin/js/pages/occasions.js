import { html, safeClass } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import { logAudit } from "../services/admin-store.js";
import {
  getEdition,
  updateEdition,
  readEditions,
  editionLabel,
} from "../services/edition.repo.js";

export const title = "Occasions";
export const perm = "occasions.view";
export const crumbs = ["Events", "Occasions"];

const slug = (s) =>
  s
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
/** UTC ISO <-> datetime-local in Lagos time (UTC+1, no daylight saving). */
const toInput = (iso) =>
  iso
    ? new Date(new Date(iso).getTime() + 36e5).toISOString().slice(0, 16)
    : "";
const fromInput = (v) => (v ? `${v}:00+01:00` : undefined);

export default async function occasions({ edition, can }) {
  return html` <div class="oga-banner" role="note">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Occasions are side events
        like <strong>Miss OGITECH</strong>, belonging to
        <strong>${editionLabel(edition)}</strong>. A <em>live</em> occasion gets
        its own page and a card on the home
        page.${can("settings.view")
          ? html` The main awards event is edited in
              <a href="${ROOT}admin/settings.html">Settings</a>.`
          : ""}</span
      >
    </div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Special occasions</h2>
        ${can("occasions.manage")
          ? html`<button class="btn btn-gold btn-sm" id="addBtn">
              <i class="fa-solid fa-plus me-1"></i>Add occasion
            </button>`
          : ""}
      </div>
      <div id="occTable"></div>
    </div>`;
}

export async function init(root, { admin, edition, can }) {
  const rows = () =>
    (getEdition(edition.id).occasions ?? []).map((o) => ({
      ...o,
      status: o.enabled ? "live" : "hidden",
      pageUrl: o.link?.href ?? "",
    }));
  const table = new DataTable(root.querySelector("#occTable"), {
    can,
    rows: rows(),
    searchKeys: ["name", "tagline"],
    columns: [
      {
        key: "name",
        label: "Occasion",
        sort: true,
        render: (o) =>
          html`<div class="oga-person">
            <span
              class="oga-icon"
              style="width:2.4rem;height:2.4rem;font-size:1rem"
              ><i class="fa-solid ${safeClass(o.icon || "fa-star")}"></i
            ></span>
            <div>
              <strong>${o.name}</strong><small>${o.tagline ?? ""}</small>
            </div>
          </div>`,
      },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (o) => statusBadge(o.status),
      },
      {
        key: "pageUrl",
        label: "Links to",
        render: (o) => html`<code class="small">${o.pageUrl || "—"}</code>`,
      },
    ],
    actions: [
      {
        key: "preview",
        label: "Open page",
        icon: "fa-arrow-up-right-from-square",
        perm: "occasions.view",
        when: (o) => o.enabled,
      },
      {
        key: "toggle",
        label: "Show / hide",
        icon: "fa-eye",
        perm: "occasions.manage",
      },
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "occasions.manage" },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "occasions.manage",
      },
    ],
    empty:
      "No special occasions yet in this edition. Add one, e.g. Miss OGITECH.",
    onAction: (key, o) => handlers[key](o),
  });
  const refresh = () => table.setRows(rows());
  const mutate = (fn) => {
    updateEdition(edition.id, (e) => {
      e.occasions ??= [];
      fn(e);
    });
    refresh();
  };
  const isLink = (v) =>
    !v || /^https?:\/\/\S+$/.test(v) || /^[a-z0-9_-]+\.html(\?\S*)?$/i.test(v);

  const fields = [
    { name: "name", label: "Name", required: true },
    {
      name: "tagline",
      label: "Tagline",
      help: "One line shown on the home page card.",
    },
    {
      name: "description",
      label: "Description",
      type: "textarea",
      rows: 4,
      help: "Shown on the occasion’s own page.",
    },
    {
      name: "icon",
      label: "Icon",
      placeholder: "fa-crown",
      help: "Font Awesome Free name, e.g. fa-crown, fa-music.",
    },
    {
      name: "eventDate",
      label: "Event date (optional)",
      type: "datetime-local",
      help: "Shown in “Key dates” on its page (Lagos time).",
    },
    {
      name: "linkHref",
      label: "Card links to",
      placeholder: "Leave empty to use its own page",
      help: "A page like occasion.html?id=… or a full https:// address.",
    },
    { name: "actionLabel", label: "Button text (optional)", col: "col-md-6" },
    {
      name: "actionHref",
      label: "Button links to",
      col: "col-md-6",
      help: "e.g. https://… or contact.html",
    },
    { type: "switch", name: "enabled", label: "Live on the public site" },
  ];
  const schema = {
    name: [rules.required(), rules.min(2), rules.max(60)],
    tagline: [rules.max(120)],
    description: [rules.max(1000)],
    icon: [
      rules.custom((v) =>
        !v || /^fa-[a-z0-9-]+$/.test(v.trim())
          ? null
          : "Use a Font Awesome name like fa-crown.",
      ),
    ],
    linkHref: [
      rules.custom((v) =>
        isLink(v?.trim())
          ? null
          : "Use a page like occasion.html?id=x or a full https:// address.",
      ),
    ],
    actionHref: [
      rules.custom((v) =>
        isLink(v?.trim())
          ? null
          : "Use a page like contact.html or a full https:// address.",
      ),
    ],
  };
  const apply = (o, v, id) => {
    Object.assign(o, {
      name: v.name.trim(),
      tagline: v.tagline.trim(),
      description: v.description.trim(),
      icon: v.icon.trim() || "fa-star",
      enabled: v.enabled,
    });
    o.link = {
      label: o.link?.label ?? "Learn more",
      href: v.linkHref.trim() || `occasion.html?id=${id}`,
    };
    o.actions =
      v.actionLabel.trim() && v.actionHref.trim()
        ? [
            {
              label: v.actionLabel.trim(),
              href: v.actionHref.trim(),
              variant: "gold",
            },
          ]
        : [];
    const t = fromInput(v.eventDate);
    o.timeline = t ? { ceremony: t } : {};
  };
  const valuesOf = (o) => ({
    ...o,
    eventDate: toInput(o.timeline?.ceremony),
    linkHref:
      o.link?.href === `occasion.html?id=${o.id}` ? "" : (o.link?.href ?? ""),
    actionLabel: o.actions?.[0]?.label ?? "",
    actionHref: o.actions?.[0]?.href ?? "",
  });

  const handlers = {
    preview: (o) =>
      window.open(
        `${ROOT}occasion.html?id=${encodeURIComponent(o.id)}`,
        "_blank",
        "noopener",
      ),
    toggle(o) {
      mutate((e) => {
        const x = e.occasions.find((y) => y.id === o.id);
        x.enabled = !x.enabled;
      });
      logAudit(
        admin,
        o.enabled ? "Hid occasion" : "Published occasion",
        o.name,
      );
      notify.toast("success", o.enabled ? "Hidden" : "Now live");
    },
    edit: (o) =>
      openFormModal({
        title: `Edit ${o.name}`,
        fields,
        schema,
        values: valuesOf(o),
        async onSubmit(v) {
          mutate((e) =>
            apply(
              e.occasions.find((y) => y.id === o.id),
              v,
              o.id,
            ),
          );
          logAudit(admin, "Edited occasion", v.name);
          notify.toast("success", "Occasion updated");
        },
      }),
    async delete(o) {
      if (
        !(await notify.confirm({
          title: `Delete ${o.name}?`,
          text: "Its page and home card will disappear.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      mutate((e) => {
        e.occasions = e.occasions.filter((y) => y.id !== o.id);
      });
      logAudit(admin, "Deleted occasion", o.name);
      notify.toast("success", "Deleted");
    },
  };

  root.querySelector("#addBtn")?.addEventListener("click", () =>
    openFormModal({
      title: "Add occasion",
      fields,
      schema,
      submitLabel: "Add occasion",
      values: { enabled: false },
      async onSubmit(v) {
        const taken = new Set(
          readEditions().editions.flatMap((e) =>
            (e.occasions ?? []).map((o) => o.id),
          ),
        );
        let id = slug(v.name) || "occasion";
        if (taken.has(id))
          id += `-${edition.id.replace(/\D/g, "") || Date.now().toString(36).slice(-4)}`;
        const o = { id, type: "special" };
        apply(o, v, id);
        mutate((e) => e.occasions.push(o));
        logAudit(admin, "Added occasion", v.name);
        notify.toast("success", "Occasion added");
      },
    }),
  );
}
