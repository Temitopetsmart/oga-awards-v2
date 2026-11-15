import { html } from "../../../shared/js/core/dom.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { fetchAllPages } from "../services/paging.js";
import { statusBadge } from "../components/badges.js";
import { statCard } from "../components/stat-card.js";
import {
  openFormModal,
  openInfoModal,
  openCustomModal,
} from "../components/modal.js";
import {
  drawTicket,
  readableOn,
} from "../../../shared/js/services/ticket-art.js";
import {
  fetchTicketsPage,
  fetchTicketTypes,
  createTicketType,
  updateTicketType,
  deleteTicketType,
  overrideTicket,
  setTicketTable,
} from "../services/ticket.admin.api.js";
export const title = "Tickets";
export const perm = "tickets.view";
export const crumbs = ["Events", "Tickets"];

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
  let typeList = await fetchTicketTypes();
  const shapeOrder = (o) => ({
    ...o,
    id: o.reference,
    buyer: o.name ?? "—",
    at: o.createdAt ?? "",
  });
  const shapeTicket = (t) => ({
    ...t,
    id: t.code,
    state: t.used ? "used" : "unused",
  });
  let wantStats = true; // totals come with the orders page; fetched again only after a change

  const stats = (s) => {
    const host = root.querySelector("#tkStats");
    host.innerHTML = "";
    [
      statCard({
        icon: "fa-ticket",
        label: "Tickets issued",
        value: number(s.issued),
        sub: `${number(s.paidQty)} paid`,
      }),
      statCard({
        icon: "fa-qrcode",
        label: "Checked in",
        value: number(s.used),
        sub: `of ${number(s.issued)}`,
      }),
      ...(money$
        ? [
            statCard({
              icon: "fa-naira-sign",
              label: "Revenue",
              value: money(s.revenue),
              sub:
                s.outstanding > 0
                  ? `${money(s.outstanding)} still to collect from sellers`
                  : "",
            }),
          ]
        : []),
      ...(s.sellerTickets > 0
        ? [
            statCard({
              icon: "fa-people-carry-box",
              label: "Sellers & bulk",
              value: number(s.sellerTickets),
              sub: "tickets handed out",
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
    { key: "typeName", label: "Type" },
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
    server: async (p) => {
      const r = await fetchTicketsPage("orders", p, { stats: wantStats });
      if (r.stats) {
        wantStats = false;
        stats(r.stats);
      }
      return { rows: r.rows.map(shapeOrder), total: r.total, page: r.page };
    },
    columns: orderCols,

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
    server: async (p) => {
      const r = await fetchTicketsPage("issued", p);
      return { rows: r.rows.map(shapeTicket), total: r.total, page: r.page };
    },
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
        key: "seat",
        label: "Seat",
        render: (t) => (t.seats > 1 ? `${t.seat} of ${t.seats}` : "—"),
      },
      { key: "tableLabel", label: "Table", render: (t) => t.tableLabel ?? "—" },
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
      {
        key: "setTable",
        label: "Set table",
        icon: "fa-chair",
        perm: "tickets.manageTypes",
      },
    ],
    empty: "No tickets issued yet in this edition.",
    async onAction(key, t) {
      if (key === "setTable") {
        openFormModal({
          title: `Table for group ${t.groupCode}`,
          fields: [
            {
              name: "tableLabel",
              label: "Table or seating note",
              help: "Applies to everyone in this group. Gate staff see it when any of their tickets is scanned.",
            },
          ],
          values: { tableLabel: t.tableLabel ?? "" },
          async onSubmit(v) {
            await setTicketTable(t.groupCode, v.tableLabel.trim());
            notify.toast("success", "Table saved");
            await refresh();
          },
        });
        return;
      }
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
      try {
        await overrideTicket(t.code, next);
      } catch (err) {
        await notify.warning("Couldn’t update the ticket", err.message);
        return;
      }
      await refresh();
    },
  });

  const typeRows = async () =>
    typeList.map((t) => ({
      ...t,
      status: t.active === false ? "hidden" : "live",
    }));
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
          html`<span
              class="badge me-2"
              style="background: ${t.look.secondary}; color: ${readableOn(
                t.look.secondary,
              )}"
              >${t.look.label}</span
            ><strong>${t.name}</strong
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

  /* ------------------------------------------------ ticket type editor (wording, price, and the look) */
  const PRESETS = {
    Regular: {
      label: "REGULAR",
      primary: "#16324f",
      secondary: "#9fb3c8",
      text: "#ffffff",
      pattern: "none",
    },
    VIP: {
      label: "VIP",
      primary: "#4a0d12",
      secondary: "#ffd24a",
      text: "#ffffff",
      pattern: "stripes",
    },
    Platinum: {
      label: "PLATINUM",
      primary: "#1c1c24",
      secondary: "#e5e4ea",
      text: "#f5f5f7",
      pattern: "diagonal",
    },
    Gold: {
      label: "GOLD",
      primary: "#3b2a05",
      secondary: "#f0c040",
      text: "#ffffff",
      pattern: "dots",
    },
    Table: {
      label: "TABLE",
      primary: "#0b3b2e",
      secondary: "#7ee0b5",
      text: "#ffffff",
      pattern: "rings",
    },
  };
  const PATTERNS = {
    none: "Plain",
    stripes: "Stripes",
    dots: "Dots",
    diagonal: "Diagonal lines",
    rings: "Rings",
  };
  const input = (id, label, value = "", extra = "") =>
    html`<div class="mb-3">
      <label class="form-label" for="${id}">${label}</label>
      <input
        class="form-control"
        id="${id}"
        name="${id}"
        value="${value}"
        ${extra}
      />
    </div>`;

  function openTypeEditor(t = null) {
    const L = t?.look ?? PRESETS.Regular;
    openCustomModal({
      title: t ? `Edit ${t.name}` : "Add ticket type",
      submitLabel: t ? "Save ticket type" : "Add ticket type",
      size: "modal-xl",
      body: html`<div class="row g-4">
        <div class="col-lg-5">
          <h3 class="h6 text-uppercase text-secondary">Ticket</h3>
          ${input("name", "Name", t?.name ?? "", "required")}
          ${input("description", "Description", t?.description ?? "")}
          <div class="row">
            <div class="col-6">
              ${input(
                "price",
                "Price (₦)",
                t?.price ?? 0,
                'type="number" min="0"',
              )}
            </div>
            <div class="col-6">
              ${input(
                "maxPerOrder",
                "Max per order",
                t?.maxPerOrder ?? 10,
                'type="number" min="1" max="50"',
              )}
            </div>
          </div>
          ${input(
            "seats",
            "Seats per ticket",
            t?.seats ?? 1,
            'type="number" min="1" max="50"',
          )}
          <small class="d-block text-secondary mb-3 mt-n2"
            >4 = a table of 4: the buyer gets 4 separate tickets, one image per
            seat.</small
          >
          <div class="mb-3">
            <label class="form-label" for="perks">Perks (one per line)</label>
            <textarea class="form-control" id="perks" name="perks" rows="3">
${(t?.perks ?? []).join("\n")}</textarea
            >
          </div>
          <div class="form-check form-switch mb-2">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="active"
              name="active"
              ${t?.active === false ? "" : "checked"}
            />
            <label class="form-check-label" for="active">On sale</label>
          </div>
          <div class="form-check form-switch">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="allowHolderNames"
              name="allowHolderNames"
              ${t?.allowHolderNames === false ? "" : "checked"}
            />
            <label class="form-check-label" for="allowHolderNames"
              >Buyers may put a name on each seat</label
            >
          </div>
        </div>
        <div class="col-lg-7">
          <h3 class="h6 text-uppercase text-secondary">How the ticket looks</h3>
          <p class="small text-secondary">
            The big word and the colours let your team tell a VIP from a Regular
            ticket at a glance, on the buyer’s phone and at the gate.
          </p>
          <div class="d-flex flex-wrap gap-2 mb-3" id="presetRow">
            ${Object.keys(PRESETS).map(
              (k) =>
                html`<button
                  class="btn btn-sm btn-outline-secondary"
                  type="button"
                  data-preset="${k}"
                >
                  ${k}
                </button>`,
            )}
          </div>
          <div class="row">
            <div class="col-md-6">
              ${input(
                "lookLabel",
                "Word on the ticket",
                L.label ?? "",
                'maxlength="16"',
              )}
            </div>
            <div class="col-md-6">
              <label class="form-label" for="lookPattern">Pattern</label>
              <select class="form-select" id="lookPattern" name="lookPattern">
                ${Object.entries(PATTERNS).map(
                  ([v, n]) =>
                    html`<option
                      value="${v}"
                      ${(L.pattern ?? "none") === v ? "selected" : ""}
                    >
                      ${n}
                    </option>`,
                )}
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-4">
              ${input(
                "lookPrimary",
                "Main colour",
                L.primary ?? "#16324f",
                'type="color" style="height:2.6rem"',
              )}
            </div>
            <div class="col-4">
              ${input(
                "lookSecondary",
                "Accent colour",
                L.secondary ?? "#9fb3c8",
                'type="color" style="height:2.6rem"',
              )}
            </div>
            <div class="col-4">
              ${input(
                "lookText",
                "Text colour",
                L.text ?? "#ffffff",
                'type="color" style="height:2.6rem"',
              )}
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="lookDetails"
              >Lines under the event name (up to 3: date, venue, gate
              time)</label
            >
            <textarea
              class="form-control"
              id="lookDetails"
              name="lookDetails"
              rows="3"
            >
${(L.details ?? []).join("\n")}</textarea
            >
          </div>
          <div class="small text-secondary mb-1">Preview</div>
          <canvas
            id="lookPreview"
            style="width:100%;height:auto;border-radius:.9rem"
            aria-label="Preview of the ticket"
          ></canvas>
        </div>
      </div>`,
      onOpen(form) {
        const el = (n) => form.elements[n];
        const look = () => ({
          label: el("lookLabel").value.trim(),
          primary: el("lookPrimary").value,
          secondary: el("lookSecondary").value,
          text: el("lookText").value,
          pattern: el("lookPattern").value,
          details: el("lookDetails")
            .value.split("\n")
            .map((x) => x.trim())
            .filter(Boolean)
            .slice(0, 3),
        });
        let busy = false;
        const paint = async () => {
          if (busy) return;
          busy = true;
          const l = look();
          await drawTicket(
            form.querySelector("#lookPreview"),
            {
              code: "TKT-PREVIEW00000",
              holder: "Guest name",
              typeName: el("name").value || "Ticket",
              seat: 1,
              seats: Number(el("seats").value) || 1,
              groupCode: "GRP-PREVIEW",
              look: {
                ...l,
                label: l.label || (el("name").value || "").toUpperCase(),
              },
              event: { name: "Your event name", edition: "Edition" },
            },
            { scale: 0.6 },
          );
          busy = false;
        };
        form.addEventListener("input", paint);
        form.querySelector("#presetRow").addEventListener("click", (e) => {
          const p = PRESETS[e.target.dataset.preset];
          if (!p) return;
          el("lookLabel").value = p.label;
          el("lookPrimary").value = p.primary;
          el("lookSecondary").value = p.secondary;
          el("lookText").value = p.text;
          el("lookPattern").value = p.pattern;
          paint();
        });
        paint();
        form.__look = look;
      },
      async onSubmit(form) {
        const v = (n) => form.elements[n];
        const body = {
          name: v("name").value.trim(),
          description: v("description").value.trim(),
          price: Number(v("price").value),
          maxPerOrder: Number(v("maxPerOrder").value),
          seats: Number(v("seats").value),
          perks: v("perks")
            .value.split("\n")
            .map((p) => p.trim())
            .filter(Boolean),
          active: v("active").checked,
          allowHolderNames: v("allowHolderNames").checked,
          look: form.__look(),
        };
        if (t) await updateTicketType(t.id, body);
        else await createTicketType(body);
        notify.toast(
          "success",
          t ? "Ticket type updated" : "Ticket type added",
        );
        await refreshTypes();
      },
    });
  }
  const refreshTypes = async () => {
    typeList = await fetchTicketTypes();
    typesTable.setRows(await typeRows());
  };

  const typeHandlers = {
    edit: (t) => openTypeEditor(t),
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
      try {
        await deleteTicketType(t.id);
      } catch (err) {
        await notify.warning("Couldn’t delete", err.message);
        return;
      }
      notify.toast("success", "Deleted");
      await refreshTypes();
    },
  };
  root
    .querySelector("#addType")
    ?.addEventListener("click", () => openTypeEditor());

  const refresh = async () => {
    wantStats = true;
    await Promise.all([ordersTable.reload(), issuedTable.reload()]);
  };
  root.querySelector("#exportBtn")?.addEventListener("click", async () => {
    // Every order that matches the current search and filters, not just this page.
    const { q, sort, filters } = ordersTable.state;
    const all = (
      await fetchAllPages(({ page, perPage }) =>
        fetchTicketsPage("orders", {
          q,
          sort: sort?.key,
          dir: sort?.dir,
          filters,
          page,
          perPage,
        }),
      )
    ).map(shapeOrder);
    downloadCsv("oga-ticket-orders.csv", all, [
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
  });
}
