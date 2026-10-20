import { html } from "../../../shared/js/core/dom.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store } from "../../../shared/js/core/store.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { statCard } from "../components/stat-card.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import { collection, logAudit } from "../services/admin-store.js";
import { scoped, inEdition } from "../services/scope.js";

export const title = "Tickets";
export const perm = "tickets.view";
export const crumbs = ["Events", "Tickets"];
const types = collection("ticket-types", {
  seed: "ticket-types",
  prefix: "TT-",
});

export default async function tickets({ can }) {
  return html` <div class="row g-3 mb-4" id="tkStats"></div>
    <div class="card">
      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
          <button
            class="nav-link active"
            data-bs-toggle="tab"
            data-bs-target="#tab-orders"
            type="button"
            role="tab"
          >
            Orders
          </button>
        </li>
        <li class="nav-item">
          <button
            class="nav-link"
            data-bs-toggle="tab"
            data-bs-target="#tab-issued"
            type="button"
            role="tab"
          >
            Issued tickets
          </button>
        </li>
        <li class="nav-item">
          <button
            class="nav-link"
            data-bs-toggle="tab"
            data-bs-target="#tab-types"
            type="button"
            role="tab"
          >
            Ticket types
          </button>
        </li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-orders" role="tabpanel">
          ${can("tickets.export")
            ? html`<div class="d-flex justify-content-end p-3 pb-0">
                <button class="btn btn-ghost btn-sm" id="exportBtn">
                  <i class="fa-solid fa-file-csv me-1"></i>Export CSV
                </button>
              </div>`
            : ""}
          <div id="ordersTable"></div>
        </div>
        <div class="tab-pane fade" id="tab-issued" role="tabpanel">
          <div id="issuedTable"></div>
        </div>
        <div class="tab-pane fade" id="tab-types" role="tabpanel">
          ${can("tickets.manageTypes")
            ? html`<div class="d-flex justify-content-end p-3 pb-0">
                <button class="btn btn-gold btn-sm" id="addType">
                  <i class="fa-solid fa-plus me-1"></i>Add ticket type
                </button>
              </div>`
            : ""}
          <div id="typesTable"></div>
        </div>
      </div>
    </div>`;
}

export async function init(root, { admin, edition, can }) {
  const money$ = can("finance.view");
  const orders = () =>
    scoped(store.get("ticket-orders", []))
      .map((o) => ({
        ...o,
        id: o.reference,
        buyer: o.name ?? "—",
        at: o.createdAt ?? "",
      }))
      .sort((a, b) => String(b.at).localeCompare(String(a.at)));
  const issued = () =>
    scoped(store.get("tickets", [])).map((t) => ({
      ...t,
      id: t.code,
      state: t.used ? "used" : "unused",
    }));

  const stats = () => {
    const paid = orders().filter((o) => o.status === "paid");
    const all = issued();
    const host = root.querySelector("#tkStats");
    host.innerHTML = "";
    [
      statCard({
        icon: "fa-ticket",
        label: "Tickets issued",
        value: number(all.length),
        sub: `${number(paid.reduce((s, o) => s + o.qty, 0))} paid`,
      }),
      statCard({
        icon: "fa-qrcode",
        label: "Checked in",
        value: number(all.filter((t) => t.used).length),
        sub: `of ${number(all.length)}`,
      }),
      ...(money$
        ? [
            statCard({
              icon: "fa-naira-sign",
              label: "Revenue",
              value: money(paid.reduce((s, o) => s + o.amount, 0)),
            }),
          ]
        : []),
    ].forEach((c) => {
      const col = document.createElement("div");
      col.className = money$ ? "col-md-4" : "col-md-6";
      col.innerHTML = c.__raw;
      host.append(col);
    });
  };

  const orderCols = [
    { key: "reference", label: "Reference", sort: true, class: "text-nowrap" },
    {
      key: "buyer",
      label: "Buyer",
      sort: true,
      render: (o) =>
        html`${o.buyer}<small class="d-block text-secondary"
            >${o.email ?? ""}</small
          >`,
    },
    { key: "typeName", label: "Type", sort: true },
    { key: "qty", label: "Qty", sort: true },
    ...(money$
      ? [
          {
            key: "amount",
            label: "Amount",
            sort: true,
            render: (o) => money(o.amount),
          },
        ]
      : []),
    {
      key: "status",
      label: "Status",
      sort: true,
      render: (o) => statusBadge(o.status),
    },
    {
      key: "at",
      label: "Date",
      sort: true,
      render: (o) =>
        o.at
          ? formatDate(o.at, { dateStyle: "medium", timeStyle: "short" })
          : "—",
    },
  ];
  const ordersTable = new DataTable(root.querySelector("#ordersTable"), {
    can,
    rows: orders(),
    columns: orderCols,
    searchKeys: ["reference", "buyer", "email", "typeName"],
    filters: [
      {
        key: "status",
        label: "Status",
        options: [
          { value: "paid", label: "Paid" },
          { value: "pending", label: "Pending" },
        ],
      },
    ],
    actions: [
      {
        key: "view",
        label: "View details",
        icon: "fa-eye",
        perm: "tickets.view",
      },
    ],
    empty: "No ticket orders yet in this edition.",
    onAction: (_, o) =>
      openInfoModal({
        title: o.reference,
        body: html`<dl class="oga-dl">
          <div>
            <dt>Buyer</dt>
            <dd>${o.buyer}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>${o.email ?? "—"}</dd>
          </div>
          <div>
            <dt>Phone</dt>
            <dd>${o.phone ?? "—"}</dd>
          </div>
          <div>
            <dt>Type</dt>
            <dd>${o.typeName}</dd>
          </div>
          <div>
            <dt>Quantity</dt>
            <dd>${o.qty}</dd>
          </div>
          ${money$
            ? html`<div>
                <dt>Amount</dt>
                <dd>${money(o.amount)}</dd>
              </div>`
            : ""}
          <div>
            <dt>Status</dt>
            <dd>${statusBadge(o.status)}</dd>
          </div>
        </dl>`,
      }),
  });

  const issuedTable = new DataTable(root.querySelector("#issuedTable"), {
    can,
    rows: issued(),
    searchKeys: ["code", "holder", "email", "typeName", "orderReference"],
    filters: [
      {
        key: "state",
        label: "Check-in",
        options: [
          { value: "used", label: "Checked in" },
          { value: "unused", label: "Not used" },
        ],
      },
    ],
    columns: [
      { key: "code", label: "Code", sort: true, class: "text-nowrap" },
      { key: "holder", label: "Holder", sort: true },
      { key: "typeName", label: "Type", sort: true },
      { key: "orderReference", label: "Order" },
      {
        key: "state",
        label: "Check-in",
        sort: true,
        render: (t) => statusBadge(t.state),
      },
    ],
    actions: [
      {
        key: "toggle",
        label: "Toggle check-in",
        icon: "fa-right-to-bracket",
        perm: "tickets.override",
      },
    ],
    empty: "No tickets issued yet in this edition.",
    async onAction(_, t) {
      const next = !t.used;
      if (
        !(await notify.confirm({
          title: next ? `Check in ${t.code}?` : `Undo check-in for ${t.code}?`,
          text: next
            ? "Marks the ticket as used."
            : "The ticket can be scanned again.",
          confirmText: next ? "Check in" : "Undo",
        }))
      )
        return;
      store.set(
        "tickets",
        store
          .get("tickets", [])
          .map((x) =>
            x.code === t.code
              ? {
                  ...x,
                  used: next,
                  usedAt: next ? new Date().toISOString() : null,
                }
              : x,
          ),
      );
      logAudit(
        admin,
        next ? "Manually checked in ticket" : "Undid ticket check-in",
        t.code,
      );
      refresh();
    },
  });

  const typeRows = async () =>
    (await types.all())
      .filter((t) => inEdition(t))
      .map((t) => ({ ...t, status: t.active === false ? "hidden" : "live" }));
  const typesTable = new DataTable(root.querySelector("#typesTable"), {
    can,
    rows: await typeRows(),
    searchKeys: ["name", "description"],
    columns: [
      {
        key: "name",
        label: "Ticket",
        sort: true,
        render: (t) =>
          html`<strong>${t.name}</strong
            ><small class="d-block text-secondary"
              >${t.description ?? ""}</small
            >`,
      },
      {
        key: "price",
        label: "Price",
        sort: true,
        render: (t) => money(t.price),
      },
      { key: "maxPerOrder", label: "Max / order", sort: true },
      {
        key: "status",
        label: "On sale",
        sort: true,
        render: (t) =>
          statusBadge(t.status, t.status === "live" ? "On sale" : "Hidden"),
      },
    ],
    actions: [
      {
        key: "edit",
        label: "Edit",
        icon: "fa-pen",
        perm: "tickets.manageTypes",
      },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "tickets.manageTypes",
      },
    ],
    empty: "No ticket types yet in this edition.",
    onAction: (key, t) => typeHandlers[key](t),
  });

  const fields = [
    { name: "name", label: "Name", required: true },
    { name: "description", label: "Description" },
    {
      name: "price",
      label: "Price (₦)",
      type: "number",
      required: true,
      attrs: 'min="0"',
    },
    {
      name: "maxPerOrder",
      label: "Max tickets per order",
      type: "number",
      required: true,
      attrs: 'min="1" max="50"',
    },
    {
      name: "perks",
      label: "Perks",
      type: "textarea",
      rows: 4,
      help: "One perk per line.",
    },
    { type: "switch", name: "active", label: "On sale" },
  ];
  const whole = (min, max) =>
    rules.custom((v) =>
      Number.isInteger(Number(v)) && Number(v) >= min && Number(v) <= max
        ? null
        : `Enter a whole number from ${min} to ${max}.`,
    );
  const schema = {
    name: [rules.required(), rules.max(60)],
    price: [rules.required(), whole(0, 10000000)],
    maxPerOrder: [rules.required(), whole(1, 50)],
  };
  const clean = (v) => ({
    name: v.name.trim(),
    description: v.description.trim(),
    price: Number(v.price),
    maxPerOrder: Number(v.maxPerOrder),
    perks: v.perks
      .split("\n")
      .map((p) => p.trim())
      .filter(Boolean),
    active: v.active,
  });
  const refreshTypes = async () => typesTable.setRows(await typeRows());

  const typeHandlers = {
    edit: (t) =>
      openFormModal({
        title: `Edit ${t.name}`,
        fields,
        schema,
        values: {
          ...t,
          perks: (t.perks ?? []).join("\n"),
          active: t.active !== false,
        },
        async onSubmit(v) {
          await types.update(t.id, clean(v));
          logAudit(admin, "Edited ticket type", v.name);
          notify.toast("success", "Ticket type updated");
          await refreshTypes();
        },
      }),
    async delete(t) {
      if (
        !(await notify.confirm({
          title: `Delete ${t.name}?`,
          text: "Existing orders keep their tickets.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      await types.remove(t.id);
      logAudit(admin, "Deleted ticket type", t.name);
      notify.toast("success", "Deleted");
      refreshTypes();
    },
  };
  root.querySelector("#addType")?.addEventListener("click", () =>
    openFormModal({
      title: "Add ticket type",
      fields,
      schema,
      submitLabel: "Add ticket type",
      values: { maxPerOrder: 10, active: true },
      async onSubmit(v) {
        await types.create({ occasionId: edition.id, ...clean(v) });
        logAudit(admin, "Added ticket type", v.name);
        notify.toast("success", "Ticket type added");
        await refreshTypes();
      },
    }),
  );

  const refresh = () => {
    ordersTable.setRows(orders());
    issuedTable.setRows(issued());
    stats();
  };
  root.querySelector("#exportBtn")?.addEventListener("click", () => {
    downloadCsv("oga-ticket-orders.csv", orders(), [
      { label: "Reference", key: "reference" },
      { label: "Buyer", key: "buyer" },
      { label: "Email", key: "email" },
      { label: "Phone", key: "phone" },
      { label: "Type", key: "typeName" },
      { label: "Quantity", key: "qty" },
      ...(money$ && can("finance.export")
        ? [{ label: "Amount", key: "amount" }]
        : []),
      { label: "Status", key: "status" },
      { label: "Date", key: "at" },
    ]);
    logAudit(admin, "Exported ticket orders");
  });
  stats();
}
