import { html } from "../../../shared/js/core/dom.js";
import { money, formatDate } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  drawTicket,
  previewTickets,
  downloadTicket,
  shareTicket,
  downloadEach,
  canShareImage,
  readableOn,
} from "../../../shared/js/services/ticket-art.js";
import { nameSeat, returnUnit } from "../services/sales.api.js";

export const SETTLE = {
  paid: "Paid online",
  owe_later: "Pay later",
  free: "Free",
};
export const ALLOC = {
  pay_now: "Pays online first",
  owe_later: "Takes now, pays later",
  free: "Free",
};

export const tierChip = (look) =>
  html`<span
    class="badge"
    style="background: ${look.secondary}; color: ${readableOn(look.secondary)}"
    >${look.label}</span
  >`;

/** The full ticket objects the image renderer needs, one per seat. */
export const withArt = (o, event) =>
  (o.tickets ?? []).map((k) => ({
    ...k,
    typeName: o.typeName,
    look: o.look,
    event,
  }));

export const batchHeading = (o) =>
  html`${tierChip(o.look)}
    <strong class="ms-2">${o.qty} × ${o.typeName}</strong>
    ${o.label ? html`<span class="ms-2">· ${o.label}</span>` : ""}
    <span class="badge text-bg-secondary ms-2"
      >${SETTLE[o.settlement] ?? o.settlement}</span
    >
    ${o.status === "pending"
      ? html`<span class="badge text-bg-warning ms-1">Awaiting payment</span>`
      : ""}
    <span class="text-secondary small ms-2"
      >${formatDate(o.createdAt, {
        dateStyle: "medium",
        timeStyle: "short",
      })}</span
    >`;

/** The tickets of one batch: one image per seat, each with its own buttons. */
export const batchBody = (o) => {
  const live = (o.tickets ?? []).filter((k) => !k.used && !k.void);
  const firstOfGroup = new Set();
  const seen = new Set();
  (o.tickets ?? []).forEach((k) => {
    if (!seen.has(k.groupCode)) {
      seen.add(k.groupCode);
      firstOfGroup.add(k.code);
    }
  });
  return html`<div class="d-flex flex-wrap gap-2 mb-3">
      <button
        class="btn btn-gold btn-sm"
        data-act="dlAll"
        ${live.length ? "" : "disabled"}
      >
        <i class="fa-solid fa-download me-1"></i>Download all ${live.length} as
        separate images
      </button>
      <button
        class="btn btn-outline-secondary btn-sm"
        data-act="print"
        ${live.length ? "" : "disabled"}
      >
        <i class="fa-solid fa-print me-1"></i>Print sheet
      </button>
      ${o.settlement === "owe_later" && o.unitPrice > 0
        ? html`<span class="small text-secondary align-self-center"
            >Owed: ${money(o.unitPrice)} per
            ${o.seats > 1 ? "table" : "ticket"}</span
          >`
        : ""}
    </div>
    <div class="row g-3">
      ${(o.tickets ?? []).map(
        (k) =>
          html`<div class="col-md-6 col-xxl-4" data-tile="${k.code}">
            <canvas
              data-ticket="${k.code}"
              width="600"
              height="260"
              style="width: 100%; height: auto; border-radius: 0.8rem"
              aria-label="Ticket ${k.code}"
            ></canvas>
            <div class="d-flex justify-content-between small mt-1">
              <span
                >${k.seats > 1 ? `Seat ${k.seat} of ${k.seats} · ` : ""}<strong
                  data-holder
                  >${k.holder}</strong
                ></span
              >
              <span class="text-secondary"
                >${k.used ? "Used" : k.void ? "Returned" : ""}</span
              >
            </div>
            <div class="d-flex flex-wrap gap-1 mt-1">
              ${k.void
                ? ""
                : html`<button
                      class="btn btn-sm btn-outline-secondary"
                      data-act="download"
                      data-code="${k.code}"
                    >
                      <i class="fa-solid fa-image me-1"></i>Image
                    </button>
                    ${canShareImage()
                      ? html`<button
                          class="btn btn-sm btn-outline-secondary"
                          data-act="share"
                          data-code="${k.code}"
                        >
                          <i class="fa-solid fa-share-nodes me-1"></i>Share
                        </button>`
                      : ""}
                    ${k.used
                      ? ""
                      : html`<button
                          class="btn btn-sm btn-outline-secondary"
                          data-act="name"
                          data-code="${k.code}"
                        >
                          <i class="fa-solid fa-pen me-1"></i>Name
                        </button>`}
                    ${!k.used &&
                    o.settlement !== "paid" &&
                    firstOfGroup.has(k.code)
                      ? html`<button
                          class="btn btn-sm btn-outline-danger"
                          data-act="return"
                          data-group="${k.groupCode}"
                        >
                          <i class="fa-solid fa-rotate-left me-1"></i
                          >Return${k.seats > 1 ? " table" : ""}
                        </button>`
                      : ""}`}
            </div>
          </div>`,
      )}
    </div>`;
};

async function printSheet(list) {
  const w = window.open("", "_blank");
  if (!w) throw new Error("Allow pop-ups for this site to print the sheet.");
  w.document.write(
    "<title>Tickets</title><style>body{margin:0}img{width:48%;margin:1%;break-inside:avoid}@page{margin:8mm}</style>",
  );
  for (const t of list) {
    const c = document.createElement("canvas");
    await drawTicket(c, t, { scale: 0.8 });
    w.document.write(`<img src="${c.toDataURL("image/png")}">`);
  }
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 500);
}

/** Makes the buttons inside `root` work for one batch. `reload()` runs after a ticket is returned. */
export function wireBatch(root, order, event, reload) {
  const items = new Map(withArt(order, event).map((t) => [t.code, t]));
  previewTickets(root, (c) => items.get(c), 0.5);
  root.addEventListener("click", async (e) => {
    const b = e.target.closest("[data-act]");
    if (!b) return;
    const t = items.get(b.dataset.code);
    try {
      if (b.dataset.act === "download") await downloadTicket(t);
      if (b.dataset.act === "share") await shareTicket(t);
      if (b.dataset.act === "dlAll") {
        const label = b.innerHTML;
        b.disabled = true;
        await downloadEach(
          [...items.values()].filter((x) => !x.used && !x.void),
          (d, n) => (b.textContent = `Saving ${d} of ${n}…`),
        );
        b.innerHTML = label;
        b.disabled = false;
      }
      if (b.dataset.act === "print")
        await printSheet([...items.values()].filter((x) => !x.used && !x.void));
      if (b.dataset.act === "name") {
        const saved = await notify.prompt({
          title: "Who will use this ticket?",
          text: "Their name is printed on the ticket image.",
          placeholder: t.holder,
          confirmText: "Save name",
          autocapitalize: "words",
          async save(name) {
            if (name.length < 2) throw new Error("Enter the person’s name.");
            await nameSeat(t.code, name);
            t.holder = name;
          },
        });
        if (saved) {
          b.closest("[data-tile]").querySelector("[data-holder]").textContent =
            t.holder;
          drawTicket(b.closest("[data-tile]").querySelector("canvas"), t, {
            scale: 0.5,
          });
          notify.toast("success", "Name saved on the ticket");
        }
      }
      if (b.dataset.act === "return") {
        const ok = await notify.confirm({
          title: "Return these tickets?",
          text: "They stop working and no longer count against what you owe. Do this only for tickets nobody took.",
          confirmText: "Return",
          danger: true,
        });
        if (!ok) return;
        await returnUnit(b.dataset.group);
        notify.toast("success", "Returned");
        await reload();
      }
    } catch (err) {
      b.disabled = false;
      notify.warning("That didn’t work", err.userMessage ?? err.message);
    }
  });
}
