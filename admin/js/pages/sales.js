import { html, mount } from "../../../shared/js/core/dom.js";
import { money, number } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { openCustomModal } from "../components/modal.js";
import {
  tierChip,
  batchHeading,
  batchBody,
  wireBatch,
  ALLOC,
} from "../components/ticket-batch.js";
import {
  fetchMySales,
  takeTickets,
  confirmSale,
} from "../services/sales.api.js";

export const title = "My ticket sales";
export const perm = "tickets.distribute";
export const crumbs = ["Events", "My ticket sales"];

export default async function sales() {
  return html`<div id="salesRoot"><p class="text-secondary">Loading…</p></div>`;
}

export async function init(root) {
  const el = root.querySelector("#salesRoot");
  let data = null;

  const load = async () => {
    data = await fetchMySales();
    render();
  };

  const render = () => {
    const { allocations: al, ledger: lg, orders, event } = data;
    const owes = al.some((a) => a.settlement === "owe_later") || lg.owed > 0;
    mount(
      el,
      html`${al.length === 0
          ? html`<div class="alert alert-secondary">
              You have no tickets allocated yet. Ask the organisers to set an
              allocation for you, then it appears here.
            </div>`
          : html`<div class="row g-3 mb-4">
              ${al.map(
                (a) =>
                  html`<div class="col-md-6 col-xl-4">
                    <div class="card h-100">
                      <div class="card-body d-flex flex-column">
                        <div class="mb-2">
                          ${tierChip(a.look)}
                          <strong class="ms-1">${a.typeName}</strong>${a.seats >
                          1
                            ? html`<span class="text-secondary small ms-1"
                                >table of ${a.seats}</span
                              >`
                            : ""}
                        </div>
                        <div class="fs-3 fw-bold">
                          ${a.settlement === "free"
                            ? "Free"
                            : money(a.unitPrice)}
                        </div>
                        <div class="small text-secondary mb-2">
                          ${ALLOC[a.settlement]}${a.settlement !== "free" &&
                          a.faceValue > a.unitPrice
                            ? ` · public price ${money(a.faceValue)}`
                            : ""}
                        </div>
                        <div class="progress mb-1" style="height: 0.5rem">
                          <div
                            class="progress-bar"
                            style="width: ${Math.min(
                              100,
                              (a.taken / a.quota) * 100,
                            )}%"
                          ></div>
                        </div>
                        <div class="small mb-3">
                          ${number(a.taken)} of ${number(a.quota)} taken ·
                          <strong>${number(a.remaining)} left</strong>
                        </div>
                        <button
                          class="btn btn-gold mt-auto"
                          data-take="${a.typeId}"
                          ${a.remaining ? "" : "disabled"}
                        >
                          <i class="fa-solid fa-ticket me-1"></i>Get tickets
                        </button>
                      </div>
                    </div>
                  </div>`,
              )}
            </div>`}
        ${owes
          ? html`<div class="card mb-4">
              <div class="card-body">
                <h2 class="h6 text-uppercase text-secondary">What you owe</h2>
                <div class="row text-center g-3">
                  <div class="col-4">
                    <div class="small text-secondary">
                      Tickets taken, pay later
                    </div>
                    <div class="fs-4">${money(lg.owed)}</div>
                  </div>
                  <div class="col-4">
                    <div class="small text-secondary">Handed in</div>
                    <div class="fs-4">${money(lg.remitted)}</div>
                  </div>
                  <div class="col-4">
                    <div class="small text-secondary">Still to hand in</div>
                    <div
                      class="fs-4 ${lg.balance > 0
                        ? "text-warning"
                        : "text-success"}"
                    >
                      ${money(Math.max(0, lg.balance))}
                    </div>
                  </div>
                </div>
                <p class="small text-secondary mb-0 mt-3">
                  Collect the money from the people you give tickets to, and
                  hand it to the treasurer. They record it here and this drops.
                  Tickets nobody took can be returned, and then they no longer
                  count.
                </p>
              </div>
            </div>`
          : ""}
        <h2 class="h5 mb-3">My tickets</h2>
        ${orders.length === 0
          ? html`<p class="text-secondary">
              Nothing yet. Tickets you take appear here, each one as its own
              image you can download or share.
            </p>`
          : orders.map(
              (o, i) =>
                html`<details class="card mb-3" ${i === 0 ? "open" : ""}>
                  <summary class="card-header" style="cursor: pointer">
                    ${batchHeading(o)}
                  </summary>
                  <div class="card-body" data-batch="${o.reference}">
                    ${batchBody(o)}
                  </div>
                </details>`,
            )}`,
    );
    orders.forEach((o) =>
      wireBatch(
        el.querySelector(`[data-batch="${o.reference}"]`),
        o,
        event,
        load,
      ),
    );
  };

  const take = (a) => {
    const price = a.settlement === "free" ? 0 : a.unitPrice;
    openCustomModal({
      title: `Get ${a.typeName}`,
      size: "modal-md",
      submitLabel:
        a.settlement === "pay_now" && price > 0
          ? "Continue to payment"
          : "Get tickets",
      body: html`<p>
          You can take up to <strong>${a.remaining}</strong> more${a.seats > 1
            ? ` (each one is a table of ${a.seats}, so ${a.seats} separate tickets)`
            : ""}.
        </p>
        <div class="mb-3">
          <label class="form-label" for="qty">How many?</label
          ><input
            class="form-control"
            id="qty"
            name="qty"
            type="number"
            min="1"
            max="${a.remaining}"
            value="1"
          />
        </div>
        <div class="mb-3">
          <label class="form-label" for="label"
            >Note for yourself (optional)</label
          ><input
            class="form-control"
            id="label"
            name="label"
            maxlength="100"
            placeholder="e.g. Hostel A, Level 2 class"
          />
        </div>
        <div class="alert alert-secondary mb-0" id="sum"></div>`,
      onOpen(form) {
        const sum = form.querySelector("#sum");
        const upd = () => {
          const n = Math.max(0, Number(form.elements.qty.value) || 0);
          const total = n * price;
          sum.textContent =
            a.settlement === "free"
              ? `${n} × free. Nothing to pay.`
              : a.settlement === "owe_later"
                ? `${n} × ${money(price)} = ${money(total)}. You take them now and hand this amount to the treasurer later.`
                : `${n} × ${money(price)} = ${money(total)}. You pay online, then the tickets appear here.`;
        };
        form.addEventListener("input", upd);
        upd();
      },
      async onSubmit(form) {
        const r = await takeTickets(
          a.typeId,
          Number(form.elements.qty.value),
          form.elements.label.value.trim(),
        );
        if (r.paymentLink) {
          location.assign(r.paymentLink);
          return;
        }
        notify.toast("success", "Your tickets are ready");
        await load();
      },
    });
  };

  el.addEventListener("click", (e) => {
    const b = e.target.closest("[data-take]");
    if (b) take(data.allocations.find((a) => a.typeId === b.dataset.take));
  });

  // coming back from the payment page
  const back = new URLSearchParams(location.search);
  const ref = back.get("tx_ref");
  if (ref) {
    history.replaceState(null, "", location.pathname);
    if (back.get("status") === "cancelled")
      notify.warning("Payment cancelled", "You have not been charged.");
    else {
      try {
        const r = await confirmSale(ref);
        if (r.status === "paid")
          notify.success("Payment confirmed", "Your tickets are ready.");
        else
          notify.warning(
            "Not confirmed yet",
            "If you were charged, your tickets appear here within a few minutes.",
          );
      } catch (err) {
        notify.warning(
          "We couldn’t confirm the payment yet",
          err.userMessage ?? err.message,
        );
      }
    }
  }
  await load();
}
