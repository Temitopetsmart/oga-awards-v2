import { html, mount, esc } from "../../../shared/js/core/dom.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { openCustomModal } from "../components/modal.js";
import {
  tierChip,
  batchHeading,
  batchBody,
  wireBatch,
  ALLOC,
  SETTLE,
} from "../components/ticket-batch.js";
import {
  fetchOverview,
  fetchBatch,
  saveAllocation,
  deleteAllocation,
  addRemittance,
  deleteRemittance,
  makeBulk,
} from "../services/sales.api.js";

export const title = "Ticket distribution";
export const perm = "tickets.allocations";
export const crumbs = ["Events", "Ticket distribution"];

export default async function distribution() {
  return html`<div class="card">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item">
        <button
          class="nav-link active"
          data-bs-toggle="tab"
          data-bs-target="#tab-sellers"
          type="button"
          role="tab"
        >
          Sellers
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          data-bs-toggle="tab"
          data-bs-target="#tab-cash"
          type="button"
          role="tab"
        >
          Cash received
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          data-bs-toggle="tab"
          data-bs-target="#tab-batches"
          type="button"
          role="tab"
        >
          All batches
        </button>
      </li>
      <li class="nav-item" id="bulkTabItem">
        <button
          class="nav-link"
          data-bs-toggle="tab"
          data-bs-target="#tab-bulk"
          type="button"
          role="tab"
        >
          Free bulk tickets
        </button>
      </li>
    </ul>
    <div class="tab-content">
      <div
        class="tab-pane fade show active p-3"
        id="tab-sellers"
        role="tabpanel"
      >
        <div
          class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"
        >
          <p class="text-secondary small mb-0">
            Give a committee member the <strong>Ticket seller</strong> role in
            Team, then set here what they may take, at what price, and how they
            settle.
          </p>
          <button class="btn btn-gold btn-sm" id="addAlloc">
            <i class="fa-solid fa-plus me-1"></i>Set up a seller
          </button>
        </div>
        <div id="allocTable"></div>
        <h2 class="h6 text-uppercase text-secondary mt-4">Who owes what</h2>
        <div id="ledgerTable"></div>
      </div>
      <div class="tab-pane fade p-3" id="tab-cash" role="tabpanel">
        <div id="cashTable"></div>
      </div>
      <div class="tab-pane fade p-3" id="tab-batches" role="tabpanel">
        <div id="batchTable"></div>
      </div>
      <div class="tab-pane fade p-3" id="tab-bulk" role="tabpanel">
        <div id="bulkBox"></div>
      </div>
    </div>
  </div>`;
}

export async function init(root, { can }) {
  let ov = await fetchOverview();
  const byId = (list, id) => list.find((x) => x.id === String(id));

  /* ---------------- sellers: allocations and the ledger */
  const allocRows = () =>
    ov.allocations.map((a) => ({
      ...a,
      state: a.active ? "on" : "off",
      look: byId(ov.types, a.typeId)?.look,
    }));
  const allocTable = new DataTable(root.querySelector("#allocTable"), {
    can,
    rows: allocRows(),
    searchKeys: ["personName", "typeName"],
    columns: [
      {
        key: "personName",
        label: "Seller",
        sort: true,
        render: (a) =>
          html`<strong>${a.personName}</strong
            ><small class="d-block text-secondary">${a.personEmail}</small>`,
      },
      {
        key: "typeName",
        label: "Ticket",
        sort: true,
        render: (a) => html`${a.look ? tierChip(a.look) : ""} ${a.typeName}`,
      },
      { key: "settlement", label: "How", render: (a) => ALLOC[a.settlement] },
      {
        key: "unitPrice",
        label: "Their price",
        sort: true,
        render: (a) =>
          a.settlement === "free"
            ? "Free"
            : html`${money(a.unitPrice)}<small class="d-block text-secondary"
                  >public ${money(a.faceValue)}</small
                >`,
      },
      {
        key: "taken",
        label: "Taken",
        sort: true,
        render: (a) => `${number(a.taken)} of ${number(a.quota)}`,
      },
      {
        key: "state",
        label: "Status",
        render: (a) =>
          a.active
            ? html`<span class="badge text-bg-success">On</span>`
            : html`<span class="badge text-bg-secondary">Off</span>`,
      },
    ],
    actions: [
      {
        key: "edit",
        label: "Edit",
        icon: "fa-pen",
        perm: "tickets.allocations",
      },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "tickets.allocations",
      },
    ],
    empty: "No sellers yet. Click “Set up a seller”.",
    onAction: (key, a) => (key === "edit" ? allocForm(a) : removeAlloc(a)),
  });
  const ledgerTable = new DataTable(root.querySelector("#ledgerTable"), {
    can,
    rows: ov.ledger.map((l) => ({ ...l, id: String(l.personId) })),
    searchKeys: ["name"],
    columns: [
      { key: "name", label: "Seller", sort: true },
      {
        key: "units",
        label: "Tickets taken",
        sort: true,
        render: (l) =>
          `${number(l.units)}${l.freeUnits ? ` (${number(l.freeUnits)} free)` : ""}`,
      },
      {
        key: "owed",
        label: "Taken, pay later",
        sort: true,
        render: (l) => money(l.owed),
      },
      {
        key: "paidOnline",
        label: "Paid online",
        render: (l) => money(l.paidOnline),
      },
      { key: "remitted", label: "Handed in", render: (l) => money(l.remitted) },
      {
        key: "balance",
        label: "Still to collect",
        sort: true,
        render: (l) =>
          html`<strong
              class="${l.balance > 0 ? "text-warning" : "text-success"}"
              >${money(Math.max(0, l.balance))}</strong
            >${l.balance < 0
              ? html`<small class="d-block text-secondary"
                  >overpaid ${money(-l.balance)}</small
                >`
              : ""}`,
      },
    ],
    actions: [
      {
        key: "cash",
        label: "Record cash received",
        icon: "fa-hand-holding-dollar",
        perm: "tickets.allocations",
      },
    ],
    empty: "Nothing taken yet.",
    onAction: (key, l) => cashForm(l),
  });

  const reload = async () => {
    ov = await fetchOverview();
    allocTable.setRows(allocRows());
    ledgerTable.setRows(
      ov.ledger.map((l) => ({ ...l, id: String(l.personId) })),
    );
    cashTable.setRows(ov.remittances);
    batchTable.setRows(batchRows());
  };

  const options = (list, sel, label) =>
    list.map(
      (x) =>
        html`<option
          value="${x.id}"
          ${String(x.id) === String(sel) ? "selected" : ""}
        >
          ${label(x)}
        </option>`,
    );

  function allocForm(a = null) {
    openCustomModal({
      title: a ? `Edit ${a.personName}: ${a.typeName}` : "Set up a seller",
      size: "modal-lg",
      submitLabel: "Save",
      body: html`<div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label" for="personId">Seller</label>
          <select
            class="form-select"
            id="personId"
            name="personId"
            ${a ? "disabled" : ""}
          >
            <option value="">Choose a person…</option>
            ${options(ov.people, a?.personId, (p) => `${p.name} (${p.email})`)}
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label" for="typeId">Ticket</label>
          <select
            class="form-select"
            id="typeId"
            name="typeId"
            ${a ? "disabled" : ""}
          >
            <option value="">Choose a ticket…</option>
            ${options(
              ov.types,
              a?.typeId,
              (t) =>
                `${t.name} · ${money(t.price)}${t.seats > 1 ? ` · table of ${t.seats}` : ""}`,
            )}
          </select>
        </div>
        <div class="col-12 mb-3">
          <label class="form-label" for="settlement">How do they settle?</label>
          <select class="form-select" id="settlement" name="settlement">
            ${[
              ["pay_now", "Pays online first, then gets the tickets"],
              ["owe_later", "Gets the tickets now, hands in the money later"],
              ["free", "Free (nothing owed)"],
            ].map(
              ([v, n]) =>
                html`<option
                  value="${v}"
                  ${(a?.settlement ?? "owe_later") === v ? "selected" : ""}
                >
                  ${n}
                </option>`,
            )}
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label" for="disc"
            >Discount off the public price (%)</label
          ><input
            class="form-control"
            id="disc"
            name="disc"
            type="number"
            min="0"
            max="100"
            placeholder="e.g. 50"
          />
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label" for="unitPrice"
            >Their price per ticket (₦)</label
          ><input
            class="form-control"
            id="unitPrice"
            name="unitPrice"
            type="number"
            min="0"
            value="${a?.unitPrice ?? 0}"
          /><small class="text-secondary" id="faceNote"></small>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label" for="quota">How many may they take?</label
          ><input
            class="form-control"
            id="quota"
            name="quota"
            type="number"
            min="1"
            value="${a?.quota ?? 10}"
          /><small class="text-secondary">A table of 4 counts as 1.</small>
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="active"
              name="active"
              ${a && !a.active ? "" : "checked"}
            /><label class="form-check-label" for="active"
              >Allocation is on</label
            >
          </div>
        </div>
      </div>`,
      onOpen(form) {
        const f = form.elements;
        const face = () =>
          byId(ov.types, f.typeId.value || a?.typeId)?.price ?? 0;
        const sync = () => {
          f.unitPrice.disabled = f.settlement.value === "free";
          f.disc.disabled = f.settlement.value === "free";
          form.querySelector("#faceNote").textContent = face()
            ? `Public price ${money(face())}`
            : "";
        };
        f.disc.addEventListener("input", () => {
          if (f.disc.value !== "")
            f.unitPrice.value = Math.round(
              face() *
                (1 - Math.min(100, Math.max(0, Number(f.disc.value))) / 100),
            );
        });
        f.unitPrice.addEventListener("input", () => (f.disc.value = ""));
        form.addEventListener("change", sync);
        sync();
      },
      async onSubmit(form) {
        const f = form.elements;
        await saveAllocation({
          personId: Number(a?.personId ?? f.personId.value),
          typeId: Number(a?.typeId ?? f.typeId.value),
          settlement: f.settlement.value,
          unitPrice:
            f.settlement.value === "free" ? 0 : Number(f.unitPrice.value),
          quota: Number(f.quota.value),
          active: f.active.checked,
        });
        notify.toast("success", "Saved");
        await reload();
      },
    });
  }
  async function removeAlloc(a) {
    if (
      !(await notify.confirm({
        title: `Delete this allocation?`,
        text: `${a.personName}: ${a.typeName}. If they have already taken tickets, switch it off instead.`,
        confirmText: "Delete",
        danger: true,
      }))
    )
      return;
    try {
      await deleteAllocation(a.id);
      notify.toast("success", "Deleted");
      await reload();
    } catch (err) {
      notify.warning("Couldn’t delete", err.userMessage ?? err.message);
    }
  }
  root.querySelector("#addAlloc").addEventListener("click", () => allocForm());

  /* ---------------- cash received */
  function cashForm(l) {
    openCustomModal({
      title: `Cash received from ${l.name}`,
      size: "modal-md",
      submitLabel: "Record",
      body: html`<p class="small text-secondary">
          Still to collect: <strong>${money(Math.max(0, l.balance))}</strong>
        </p>
        <div class="mb-3">
          <label class="form-label" for="amount">Amount received (₦)</label
          ><input
            class="form-control"
            id="amount"
            name="amount"
            type="number"
            min="1"
            value="${l.balance > 0 ? l.balance : ""}"
          />
        </div>
        <div class="mb-3">
          <label class="form-label" for="method">How</label
          ><select class="form-select" id="method" name="method">
            <option value="cash">Cash</option>
            <option value="transfer">Bank transfer</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="note">Note (optional)</label
          ><input class="form-control" id="note" name="note" maxlength="255" />
        </div>`,
      async onSubmit(form) {
        await addRemittance({
          personId: l.personId,
          amount: Number(form.elements.amount.value),
          method: form.elements.method.value,
          note: form.elements.note.value.trim(),
        });
        notify.toast("success", "Recorded");
        await reload();
      },
    });
  }
  const cashTable = new DataTable(root.querySelector("#cashTable"), {
    can,
    rows: ov.remittances,
    searchKeys: ["personName", "note"],
    columns: [
      { key: "personName", label: "From", sort: true },
      {
        key: "amount",
        label: "Amount",
        sort: true,
        render: (r) => money(r.amount),
      },
      { key: "method", label: "How" },
      { key: "note", label: "Note", render: (r) => r.note ?? "" },
      { key: "recordedBy", label: "Recorded by" },
      {
        key: "createdAt",
        label: "When",
        sort: true,
        render: (r) =>
          formatDate(r.createdAt, { dateStyle: "medium", timeStyle: "short" }),
      },
    ],
    actions: [
      {
        key: "delete",
        label: "Remove",
        icon: "fa-trash",
        perm: "tickets.allocations",
      },
    ],
    empty: "No money recorded yet.",
    onAction: async (key, r) => {
      if (
        !(await notify.confirm({
          title: "Remove this entry?",
          text: `${r.personName}: ${money(r.amount)}. Use this only to correct a mistake.`,
          confirmText: "Remove",
          danger: true,
        }))
      )
        return;
      try {
        await deleteRemittance(r.id);
        await reload();
      } catch (err) {
        notify.warning("Couldn’t remove", err.userMessage ?? err.message);
      }
    },
  });

  /* ---------------- every batch, open to see its tickets */
  const batchRows = () => ov.batches.map((b) => ({ ...b, id: b.reference }));
  async function openBatch(ref) {
    const r = await fetchBatch(ref);
    openCustomModal({
      title: `Batch ${ref}`,
      size: "modal-xl",
      submitLabel: "Close",
      body: html`<div id="batchHost"></div>`,
      onOpen(form) {
        const host = form.querySelector("#batchHost");
        const show = (order) => {
          const frag = document.createElement("div");
          host.replaceChildren(frag);
          mount(
            frag,
            html`<div class="mb-3">${batchHeading(order)}</div>
              <div data-batch>${batchBody(order)}</div>`,
          );
          wireBatch(
            frag.querySelector("[data-batch]"),
            order,
            r.event,
            async () => {
              show((await fetchBatch(ref)).order);
              await reload();
            },
          );
        };
        show(r.order);
      },
      async onSubmit() {},
    });
  }
  const batchTable = new DataTable(root.querySelector("#batchTable"), {
    can,
    rows: batchRows(),
    searchKeys: ["issuedBy", "label", "typeName", "reference"],
    columns: [
      {
        key: "typeName",
        label: "Batch",
        sort: true,
        render: (b) =>
          html`${tierChip(b.look)}
            <strong class="ms-1">${b.qty} × ${b.typeName}</strong>${b.label
              ? html`<small class="d-block text-secondary">${b.label}</small>`
              : ""}`,
      },
      { key: "issuedBy", label: "Made by", sort: true },
      {
        key: "channel",
        label: "Kind",
        render: (b) => (b.channel === "bulk" ? "Free bulk" : "Seller"),
      },
      {
        key: "settlement",
        label: "How",
        render: (b) => SETTLE[b.settlement] ?? "",
      },
      {
        key: "createdAt",
        label: "When",
        sort: true,
        render: (b) =>
          formatDate(b.createdAt, { dateStyle: "medium", timeStyle: "short" }),
      },
    ],
    actions: [{ key: "open", label: "Open tickets", icon: "fa-ticket" }],
    empty: "No batches yet.",
    onAction: (key, b) =>
      openBatch(b.reference).catch((err) =>
        notify.warning("Couldn’t open it", err.userMessage ?? err.message),
      ),
  });

  /* ---------------- free bulk tickets (Super admin, or anyone given that permission) */
  const bulkBox = root.querySelector("#bulkBox");
  if (!ov.canBulk) root.querySelector("#bulkTabItem").remove();
  else {
    bulkBox.innerHTML = "";
    const form = document.createElement("form");
    form.noValidate = true;
    form.className = "col-lg-6 p-0";
    form.innerHTML = `<p class="text-secondary">Make free tickets for sponsors, guests, press or anyone you are giving a ticket to. Each seat is its own ticket image. Nothing is charged and it adds no revenue.</p>
      <div class="mb-3"><label class="form-label" for="bType">Ticket</label><select class="form-select" id="bType">${ov.types.map((t) => `<option value="${esc(t.id)}">${esc(t.name)}${t.seats > 1 ? ` (table of ${Number(t.seats)})` : ""}</option>`).join("")}</select></div>
      <div class="mb-3"><label class="form-label" for="bQty">How many?</label><input class="form-control" id="bQty" type="number" min="1" value="10" /><small class="text-secondary">A table of 4 counts as 1 and gives 4 tickets.</small></div>
      <div class="mb-3"><label class="form-label" for="bLabel">Label (so you can find it later)</label><input class="form-control" id="bLabel" maxlength="100" placeholder="e.g. Sponsors, Press, Guests of honour" /></div>
      <button class="btn btn-gold" type="submit">Generate tickets</button>`;
    bulkBox.append(form);
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const btn = form.querySelector("button");
      btn.disabled = true;
      try {
        const r = await makeBulk({
          typeId: Number(form.querySelector("#bType").value),
          qty: Number(form.querySelector("#bQty").value),
          label: form.querySelector("#bLabel").value.trim(),
        });
        notify.toast("success", "Tickets generated");
        form.querySelector("#bLabel").value = "";
        await reload();
        await openBatch(r.reference);
      } catch (err) {
        notify.warning("Couldn’t generate", err.userMessage ?? err.message);
      } finally {
        btn.disabled = false;
      }
    });
  }
}
