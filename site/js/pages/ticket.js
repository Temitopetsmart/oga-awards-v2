import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  findTickets,
  setTicketHolder,
} from "../../../shared/js/services/ticket.service.js";
import {
  drawTicket,
  previewTickets,
  downloadTicket,
  shareTicket,
  downloadEach,
  canShareImage,
} from "../../../shared/js/services/ticket-art.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

export const title = "Your Ticket";

let LIST = []; // the tickets on screen, so a button can find its ticket by code
let EMAIL = "";
const find = (code) => LIST.find((t) => t.code === code);
const crumbs = [
  { label: "Tickets", href: "tickets" },
  { label: "Your ticket" },
];

export default async function ticket(ctx, { params }) {
  const ref = params.get("ref") ?? params.get("code");
  const email = params.get("email");
  if (!ref || !email)
    return html`${pageHero({ title: "Your ticket", crumbs })}${notice({
      icon: "fa-ticket",
      title: "No ticket selected",
      text: "Retrieve your ticket with your reference and email.",
      actions: [{ label: "Go to tickets", href: "tickets" }],
    })}`;

  try {
    LIST = (await findTickets({ ref, email })).map((t) => ({
      ...t,
      event: t.event ?? {
        name: ctx.occasion.name,
        edition: ctx.occasion.edition,
      },
    }));
  } catch (err) {
    return html`${pageHero({ title: "Your ticket", crumbs })}${notice({
      icon: "fa-circle-exclamation",
      title: "Ticket not found",
      text: err.userMessage ?? "We could not find that ticket.",
      actions: [{ label: "Try again", href: "tickets" }],
    })}`;
  }
  EMAIL = email;

  // seats of one table stay together
  const groups = [];
  for (const t of LIST) {
    let g = groups.find((x) => x.code === t.groupCode);
    if (!g) groups.push((g = { code: t.groupCode, tickets: [] }));
    g.tickets.push(t);
  }
  const live = LIST.filter((t) => !t.used);
  const seatCard = (t) =>
    html` <article class="oga-ticketcard" data-card="${t.code}">
      <canvas
        data-ticket="${t.code}"
        class="oga-ticketcard__img"
        role="img"
        aria-label="${t.look.label} ticket for ${t.holder}, code ${t.code}"
      ></canvas>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-2 no-print">
        ${t.used
          ? html`<span class="badge text-bg-secondary"
              >Already used at the gate</span
            >`
          : html`<button
                class="btn btn-gold btn-sm"
                data-act="download"
                data-code="${t.code}"
              >
                <i class="fa-solid fa-image me-1"></i>Download image
              </button>
              ${canShareImage()
                ? html`<button
                    class="btn btn-ghost btn-sm"
                    data-act="share"
                    data-code="${t.code}"
                  >
                    <i class="fa-solid fa-share-nodes me-1"></i>Share
                  </button>`
                : ""}
              ${t.canRename
                ? html`<button
                    class="btn btn-ghost btn-sm"
                    data-act="rename"
                    data-code="${t.code}"
                  >
                    <i class="fa-solid fa-pen me-1"></i>Name this seat
                  </button>`
                : ""}`}
      </div>
    </article>`;

  return html`${pageHero({
    eyebrow: `${LIST[0].event.name} ${LIST[0].event.edition}`,
    title: LIST.length > 1 ? `Your ${LIST.length} tickets` : "Your ticket",
    lead: "Every seat has its own ticket with its own QR code. Download each one as an image and send it to the person who is coming. Each code works once.",
    crumbs,
  })}
  ${pageBody(
    html`${LIST.length > 1 && live.length > 0
        ? html`<div
            class="d-flex flex-wrap gap-2 justify-content-center mb-4 no-print"
          >
            <button class="btn btn-gold" id="downloadAll">
              <i class="fa-solid fa-download me-2"></i>Download all
              ${live.length} as separate images
            </button>
            <button class="btn btn-ghost" id="printBtn">
              <i class="fa-solid fa-print me-2"></i>Print / save as PDF
            </button>
          </div>`
        : html`<div class="d-flex justify-content-center mb-4 no-print">
            <button class="btn btn-ghost" id="printBtn">
              <i class="fa-solid fa-print me-2"></i>Print / save as PDF
            </button>
          </div>`}
      <div class="d-grid gap-5">
        ${groups.map(
          (g) =>
            html`<section>
              ${g.tickets.length > 1
                ? html`<h2 class="h5 mb-3">
                    ${g.tickets[0].typeName}${g.tickets[0].tableLabel
                      ? ` · ${g.tickets[0].tableLabel}`
                      : ""}
                    <span class="text-secondary fs-6"
                      >${g.tickets.length} seats</span
                    >
                  </h2>`
                : ""}
              <div class="d-grid gap-4">${g.tickets.map(seatCard)}</div>
            </section>`,
        )}
      </div>
      <div class="text-center mt-5 no-print">
        <a class="btn btn-ghost" href="${ROOT}">Back home</a>
      </div>`,
    { width: "52rem" },
  )}`;
}

export async function init(root) {
  root.querySelector("#printBtn")?.addEventListener("click", () => print());
  previewTickets(root, find, 0.7);

  const redraw = (code) => {
    const canvas = root.querySelector(`canvas[data-ticket="${code}"]`);
    if (canvas) drawTicket(canvas, find(code), { scale: 0.7 });
  };

  root.querySelector("#downloadAll")?.addEventListener("click", async (e) => {
    const btn = e.currentTarget;
    const label = btn.innerHTML;
    btn.disabled = true;
    try {
      await downloadEach(
        LIST.filter((t) => !t.used),
        (d, n) => (btn.textContent = `Saving ${d} of ${n}…`),
      );
      notify.toast("success", "All tickets saved as separate images");
    } catch (err) {
      notify.warning("Couldn’t save the images", err.message);
    } finally {
      btn.innerHTML = label;
      btn.disabled = false;
    }
  });

  root.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-act]");
    if (!btn) return;
    const t = find(btn.dataset.code);
    if (!t) return;
    try {
      if (btn.dataset.act === "download") await downloadTicket(t);
      if (btn.dataset.act === "share") await shareTicket(t);
      if (btn.dataset.act === "rename") {
        const saved = await notify.prompt({
          title: "Who will use this seat?",
          text: "Their name will be printed on this ticket.",
          placeholder: t.holder,
          confirmText: "Save name",
          autocapitalize: "words",
          async save(name) {
            if (name.length < 2) throw new Error("Enter the person’s name.");
            t.holder = await setTicketHolder({
              code: t.code,
              email: EMAIL,
              name,
            });
          },
        });
        if (!saved) return;
        redraw(t.code);
        notify.toast("success", "Name saved on the ticket");
      }
    } catch (err) {
      notify.warning("That didn’t work", err.userMessage ?? err.message);
    }
  });
}
