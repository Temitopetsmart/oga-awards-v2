import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { findTickets } from "../../../shared/js/services/ticket.service.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

export const title = "Your Ticket";
const QR_LIB = "https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js";

const loadScript = (src) =>
  new Promise((resolve, reject) => {
    if (window.qrcode) return resolve();
    const s = document.createElement("script");
    s.src = src;
    s.onload = resolve;
    s.onerror = () => reject(new Error("QR library failed to load"));
    document.head.append(s);
  });

export default async function ticket(ctx, { params }) {
  const ref = params.get("ref") ?? params.get("code");
  const email = params.get("email");
  if (!ref || !email)
    return html`${pageHero({
      title: "Your ticket",
      crumbs: [
        { label: "Tickets", href: "tickets.html" },
        { label: "Your ticket" },
      ],
    })}${notice({
      icon: "fa-ticket",
      title: "No ticket selected",
      text: "Retrieve your ticket with your reference and email.",
      actions: [{ label: "Go to tickets", href: "tickets.html" }],
    })}`;

  let list;
  try {
    list = await findTickets({ ref, email });
  } catch (err) {
    return html`${pageHero({
      title: "Your ticket",
      crumbs: [
        { label: "Tickets", href: "tickets.html" },
        { label: "Your ticket" },
      ],
    })}${notice({
      icon: "fa-circle-exclamation",
      title: "Ticket not found",
      text: err.userMessage ?? "We could not find that ticket.",
      actions: [{ label: "Try again", href: "tickets.html" }],
    })}`;
  }
  return html` ${pageHero({
    eyebrow: `${ctx.occasion.name} ${ctx.occasion.edition}`,
    title: list.length > 1 ? `Your ${list.length} tickets` : "Your ticket",
    lead: "Show the QR code at the gate. Each code can be scanned once.",
    crumbs: [
      { label: "Tickets", href: "tickets.html" },
      { label: "Your ticket" },
    ],
  })}
  ${pageBody(
    html` <div class="d-grid gap-4">
        ${list.map(
          (t) =>
            html` <article class="oga-ticket">
              <div class="oga-ticket__main">
                <span class="oga-badge mb-3">${t.typeName}</span>
                <h2 class="h3">
                  ${ctx.occasion.name}
                  <span class="text-gold">${ctx.occasion.edition}</span>
                </h2>
                <p class="mb-1">Ticket holder</p>
                <p class="fs-5 text-white mb-3">${t.holder}</p>
                <p class="mb-1">Ticket code</p>
                <p class="oga-ticket__code fs-5 mb-0">${t.code}</p>
              </div>
              <div
                class="oga-ticket__qr"
                data-qr="${t.code}"
                role="img"
                aria-label="QR code for ${t.code}"
              ></div>
            </article>`,
        )}
      </div>
      <div class="d-flex flex-wrap gap-3 justify-content-center mt-4 no-print">
        <button class="btn btn-gold" id="printBtn">
          <i class="fa-solid fa-print me-2"></i>Print / save as PDF
        </button>
        <a class="btn btn-ghost" href="${ROOT}index.html">Back home</a>
      </div>`,
    { width: "48rem" },
  )}`;
}

export async function init(root) {
  root.querySelector("#printBtn")?.addEventListener("click", () => print());
  try {
    await loadScript(QR_LIB);
    root.querySelectorAll("[data-qr]").forEach((el) => {
      const qr = window.qrcode(0, "M");
      qr.addData(el.dataset.qr);
      qr.make();
      el.innerHTML = qr.createSvgTag({
        cellSize: 4,
        margin: 0,
        scalable: true,
      });
    });
  } catch (err) {
    console.error(err);
    root.querySelectorAll("[data-qr]").forEach((el) => {
      el.textContent = "QR unavailable — show the ticket code at the gate.";
      el.style.color = "#000";
    });
  }
}
