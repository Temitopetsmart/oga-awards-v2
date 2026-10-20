import { html, safeClass } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import {
  listCategories,
  nomineeCounts,
  createCategory,
  updateCategory,
  deleteCategory,
} from "../services/category.repo.js";
import { logAudit } from "../services/admin-store.js";

export const title = "Categories";
export const perm = "categories.view";
export const crumbs = ["Awards", "Categories"];

export default async function categories({ can }) {
  return html`<div class="card">
    <div
      class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
    >
      <h2 class="card-title mb-0">Award categories</h2>
      ${can("categories.manage")
        ? html`<button class="btn btn-gold btn-sm" id="addBtn">
            <i class="fa-solid fa-plus me-1"></i>Add category
          </button>`
        : ""}
    </div>
    <div id="catTable"></div>
  </div>`;
}

export async function init(root, { admin, can }) {
  const load = async () => {
    const [cats, counts] = await Promise.all([
      listCategories(),
      nomineeCounts(),
    ]);
    return cats.map((c) => ({
      ...c,
      nominees: counts[c.id] ?? 0,
      statusKey: c.active === false ? "inactive" : "active",
    }));
  };

  const fields = [
    { name: "name", label: "Name", required: true },
    {
      name: "description",
      label: "Description",
      type: "textarea",
      rows: 3,
      required: true,
      help: "One short sentence shown on the public site.",
    },
    {
      name: "icon",
      label: "Icon",
      placeholder: "fa-trophy",
      help: "A Font Awesome Free icon name, e.g. fa-trophy, fa-lightbulb.",
    },
    {
      name: "order",
      label: "Display order",
      type: "number",
      required: true,
      help: "Lower numbers appear first.",
    },
    { type: "switch", name: "active", label: "Visible on the public site" },
  ];
  const schema = {
    name: [rules.required(), rules.min(2), rules.max(60)],
    description: [rules.required(), rules.max(160)],
    icon: [
      rules.custom((v) =>
        !v || /^fa-[a-z0-9-]+$/.test(v.trim())
          ? null
          : "Use a Font Awesome name like fa-trophy.",
      ),
    ],
    order: [
      rules.required(),
      rules.custom((v) =>
        Number.isInteger(Number(v)) && Number(v) >= 0
          ? null
          : "Enter a whole number, 0 or more.",
      ),
    ],
  };
  const clean = (v) => ({
    name: v.name.trim(),
    description: v.description.trim(),
    icon: v.icon.trim() || "fa-award",
    order: Number(v.order),
    active: v.active,
  });

  const table = new DataTable(root.querySelector("#catTable"), {
    can,
    rows: await load(),
    searchKeys: ["name", "description"],
    filters: [
      {
        key: "statusKey",
        label: "Visibility",
        options: [
          { value: "active", label: "Visible" },
          { value: "inactive", label: "Hidden" },
        ],
      },
    ],
    columns: [
      { key: "order", label: "#", sort: true, class: "text-secondary" },
      {
        key: "name",
        label: "Category",
        sort: true,
        render: (c) =>
          html`<div class="oga-person">
            <span
              class="oga-icon"
              style="width:2.4rem;height:2.4rem;font-size:1rem"
              ><i class="fa-solid ${safeClass(c.icon || "fa-award")}"></i
            ></span>
            <div><strong>${c.name}</strong><small>${c.description}</small></div>
          </div>`,
      },
      { key: "nominees", label: "Nominees", sort: true },
      {
        key: "statusKey",
        label: "Visibility",
        sort: true,
        render: (c) =>
          statusBadge(
            c.statusKey,
            c.statusKey === "active" ? "Visible" : "Hidden",
          ),
      },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen", perm: "categories.manage" },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "categories.manage",
      },
    ],
    empty: "No categories yet in this edition.",
    onAction: (key, c) => handlers[key](c),
  });
  const refresh = async () => table.setRows(await load());

  const handlers = {
    edit: (c) =>
      openFormModal({
        title: `Edit ${c.name}`,
        fields,
        schema,
        values: { ...c, active: c.active !== false },
        async onSubmit(v) {
          await updateCategory(c.id, clean(v));
          logAudit(admin, "Edited category", v.name);
          notify.toast("success", "Category updated");
          await refresh();
        },
      }),
    async delete(c) {
      if (c.nominees > 0) {
        await notify.warning(
          "Can’t delete yet",
          `“${c.name}” still has ${c.nominees} nominee${c.nominees === 1 ? "" : "s"}. Move or remove them first, or hide the category instead.`,
        );
        return;
      }
      if (
        !(await notify.confirm({
          title: `Delete ${c.name}?`,
          text: "This can’t be undone.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      await deleteCategory(c.id);
      logAudit(admin, "Deleted category", c.name);
      notify.toast("success", "Category deleted");
      refresh();
    },
  };

  root.querySelector("#addBtn")?.addEventListener("click", async () => {
    const next =
      (await listCategories()).reduce((m, c) => Math.max(m, c.order ?? 0), 0) +
      1;
    openFormModal({
      title: "Add category",
      fields,
      schema,
      submitLabel: "Add category",
      values: { order: next, active: true },
      async onSubmit(v) {
        await createCategory(clean(v));
        logAudit(admin, "Added category", v.name);
        notify.toast("success", "Category added");
        await refresh();
      },
    });
  });
}
