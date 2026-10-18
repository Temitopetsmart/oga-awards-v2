import { html, safeClass } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import { documentStore, logAudit } from "../services/admin-store.js";

export const title = "Occasions";
export const perm = "occasions";
export const crumbs = ["Events", "Occasions"];
const doc = documentStore("occasions", { seed: "occasions" });

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

export default async function occasions() {
  return html` <div
      class="alert mb-4"
      role="note"
      style="background:rgba(212,175,55,.08);border:1px solid var(--oga-border-strong);color:var(--oga-gold-200)"
    >
      <i class="fa-solid fa-circle-info me-2"></i>Occasions are side events like
      <strong>Miss OGITECH</strong>. A <em>live</em> occasion gets its own page
      and a card on the home page. The main awards event is edited in
      <a href="${ROOT}admin/settings.html">Settings</a>.
    </div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Special occasions</h2>
        <button class="btn btn-gold btn-sm" id="addBtn">
          <i class="fa-solid fa-plus me-1"></i>Add occasion
        </button>
      </div>
      <div id="occTable"></div>
    </div>`;
}

export async function init(root, { admin }) {
  const rows = async () =>
    (await doc.get()).occasions
      .filter((o) => o.type === "special")
      .map((o) => ({
        ...o,
        status: o.enabled ? "live" : "hidden",
        pageUrl: o.link?.href ?? "",
      }));
  const table = new DataTable(root.querySelector("#occTable"), {
    rows: await rows(),
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
        when: (o) => o.enabled,
      },
      { key: "toggle", label: "Show / hide", icon: "fa-eye" },
      { key: "edit", label: "Edit", icon: "fa-pen" },
      { key: "delete", label: "Delete", icon: "fa-trash" },
    ],
    empty: "No special occasions yet. Add one, e.g. Miss OGITECH.",
    onAction: (key, o) => handlers[key](o),
  });
  const refresh = async () => table.setRows(await rows());

  const mutate = async (fn) => {
    const d = await doc.get();
    fn(d);
    doc.save(d);
    await refresh();
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
    async toggle(o) {
      await mutate((d) => {
        const x = d.occasions.find((y) => y.id === o.id);
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
          await mutate((d) =>
            apply(
              d.occasions.find((y) => y.id === o.id),
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
      await mutate((d) => {
        d.occasions = d.occasions.filter((y) => y.id !== o.id);
      });
      logAudit(admin, "Deleted occasion", o.name);
      notify.toast("success", "Deleted");
    },
  };

  root.querySelector("#addBtn").addEventListener("click", () =>
    openFormModal({
      title: "Add occasion",
      fields,
      schema,
      submitLabel: "Add occasion",
      values: { enabled: false },
      async onSubmit(v) {
        const d = await doc.get();
        let id = slug(v.name) || "occasion";
        if (d.occasions.some((o) => o.id === id))
          id += `-${Date.now().toString(36).slice(-4)}`;
        const o = { id, type: "special" };
        apply(o, v, id);
        d.occasions.push(o);
        doc.save(d);
        logAudit(admin, "Added occasion", v.name);
        notify.toast("success", "Occasion added");
        await refresh();
      },
    }),
  );
}
