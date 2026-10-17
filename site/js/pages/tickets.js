import { html, mount } from "../../../shared/js/core/dom.js";
import { money } from "../../../shared/js/core/format.js";
import { go } from "../../../shared/js/core/nav.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { hasFeature } from "../../../shared/js/services/event.service.js";
import {
  getTicketTypes,
  createTicketOrder,
  issueTickets,
  findTickets,
} from "../../../shared/js/services/ticket.service.js";
import { checkout } from "../../../shared/js/services/payment.service.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

export const title = "Tickets";
let types = [];

export default async function tickets(ctx) {
  const hero = pageHero({
    eyebrow: "Awards night",
    title: "Get your tickets",
    lead: "Choose a ticket, pay securely and get your QR code instantly.",
    crumbs: [{ label: "Tickets" }],
  });
  if (!hasFeature(ctx.occasion, "tickets")) {
    return html`${hero}${notice({
      icon: "fa-ticket",
      title: "Tickets aren’t on sale yet",
      text: "We’ll announce when ticket sales open.",
      actions: [{ label: "Back home", href: "index.html" }],
    })}`;
  }
  types = await getTicketTypes(ctx.occasion.id);
  return html`${hero}${pageBody(
    html` <div class="row g-4" id="ticketTypes">
        ${types.map(
          (t) =>
            html` <div class="col-md-6 col-lg-4">
              <article class="oga-card" data-type-card="${t.id}">
                <h3 class="mt-0">${t.name}</h3>
                <div class="oga-price">${money(t.price)}</div>
                <p>${t.description}</p>
                <ul class="oga-perks">
                  ${t.perks.map(
                    (p) => html`<li><i class="fa-solid fa-check"></i>${p}</li>`,
                  )}
                </ul>
                <button class="btn btn-gold mt-auto" data-select="${t.id}">
                  Select ticket
                </button>
              </article>
            </div>`,
        )}
      </div>
      <div id="checkout" class="mt-5"></div>
      <hr class="my-5" />
      <div class="row justify-content-center">
        <div class="col-lg-7">
          <h2 class="h4">Already bought? Retrieve your ticket</h2>
          <form id="retrieveForm" class="oga-formcard mt-3" novalidate>
            <div class="row">
              ${field({
                label: "Ticket code or order reference",
                name: "ref",
                required: true,
                placeholder: "e.g. TKT-XXXXXXXX",
                col: "col-md-6",
              })}
              ${field({
                label: "Email used to buy",
                name: "email",
                type: "email",
                required: true,
                autocomplete: "email",
                col: "col-md-6",
              })}
            </div>
            <button class="btn btn-ghost" type="submit">
              <i class="fa-solid fa-magnifying-glass me-2"></i>Find my ticket
            </button>
          </form>
        </div>
      </div>`,
  )}`;
}

export function init(root) {
  const box = root.querySelector("#checkout");
  if (!box) return; // absent when ticket sales are off
  root.querySelector("#ticketTypes").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-select]");
    if (!btn) return;
    const type = types.find((t) => t.id === btn.dataset.select);
    root
      .querySelectorAll("[data-type-card]")
      .forEach((c) =>
        c.classList.toggle("is-selected", c.dataset.typeCard === type.id),
      );
    renderCheckout(box, type);
    box.scrollIntoView({ behavior: "smooth", block: "center" });
  });

  bindForm(root.querySelector("#retrieveForm"), {
    schema: {
      ref: [rules.required()],
      email: [rules.required(), rules.email()],
    },
    async onSubmit({ ref, email }) {
      const found = await findTickets({ ref, email });
      go(
        `ticket.html?ref=${encodeURIComponent(ref.trim())}&email=${encodeURIComponent(email.trim())}&n=${found.length}`,
      );
    },
  });
}

function renderCheckout(box, type) {
  mount(
    box,
    html` <form
      id="buyForm"
      class="oga-formcard mx-auto"
      style="max-width:40rem"
      novalidate
    >
      <h2 class="h4 mb-3">${type.name} ticket</h2>
      ${field({
        label: "Quantity",
        name: "qty",
        options: Array.from({ length: type.maxPerOrder }, (_, i) => ({
          value: i + 1,
          label: String(i + 1),
        })),
        value: 1,
        required: true,
      })}
      ${field({
        label: "Full name",
        name: "name",
        required: true,
        autocomplete: "name",
      })}
      <div class="row">
        ${field({
          label: "Email",
          name: "email",
          type: "email",
          required: true,
          autocomplete: "email",
          col: "col-md-6",
          help: "Your tickets are tied to this email.",
        })}
        ${field({
          label: "Phone",
          name: "phone",
          type: "tel",
          required: true,
          autocomplete: "tel",
          col: "col-md-6",
        })}
      </div>
      <div class="oga-total">
        <span>Total</span><strong id="buyTotal">${money(type.price)}</strong>
      </div>
      <button class="btn btn-gold btn-lg w-100" type="submit">
        <i class="fa-solid fa-lock me-2"></i>Pay & get tickets
      </button>
    </form>`,
  );
  const form = box.querySelector("#buyForm");
  form.elements.qty.addEventListener("change", () => {
    box.querySelector("#buyTotal").textContent = money(
      type.price * Number(form.elements.qty.value || 1),
    );
  });
  bindForm(form, {
    schema: {
      qty: [rules.required("Choose a quantity.")],
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      phone: [rules.required(), rules.phone()],
    },
    async onSubmit(v) {
      const order = await createTicketOrder({
        type,
        qty: Number(v.qty),
        name: v.name.trim(),
        email: v.email.trim(),
        phone: v.phone.trim(),
      });
      await checkout(order);
      const { tickets: issued } = await issueTickets(order.reference);
      await notify.success(
        "Payment successful",
        `Your ticket${issued.length > 1 ? "s are" : " is"} ready.`,
      );
      go(
        `ticket.html?ref=${encodeURIComponent(order.reference)}&email=${encodeURIComponent(order.email)}`,
      );
    },
  });
}
