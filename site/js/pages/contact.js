import { APP } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { sendContactMessage } from "../../../shared/js/services/forms.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "Contact";

export default async function contact() {
  const { email, phone } = APP.contact;
  return html` ${pageHero({
    eyebrow: "Get in touch",
    title: "Contact us",
    lead: "Questions, partnerships or feedback — we’d love to hear from you.",
    crumbs: [{ label: "Contact" }],
  })}
  ${pageBody(
    html` <div class="row gy-5 gx-lg-5">
      <div class="col-lg-7">
        <form id="contactForm" class="oga-formcard" novalidate>
          <div class="row">
            ${field({
              label: "Full name",
              name: "name",
              required: true,
              autocomplete: "name",
              col: "col-md-6",
            })}
            ${field({
              label: "Email",
              name: "email",
              type: "email",
              required: true,
              autocomplete: "email",
              col: "col-md-6",
            })}
          </div>
          ${field({ label: "Subject", name: "subject", required: true })}
          ${field({
            label: "Message",
            name: "message",
            type: "textarea",
            rows: 6,
            required: true,
            help: "At least 20 characters.",
          })}
          <button class="btn btn-gold btn-lg" type="submit">
            <i class="fa-solid fa-paper-plane me-2"></i>Send message
          </button>
        </form>
      </div>
      <div class="col-lg-5">
        <div class="oga-card">
          <h2 class="h4 mb-3">Reach us directly</h2>
          <ul class="list-unstyled oga-footer__links mb-0">
            ${email
              ? html`<li>
                  <i class="fa-solid fa-envelope"></i
                  ><a href="mailto:${email}">${email}</a>
                </li>`
              : ""}
            ${phone
              ? html`<li>
                  <i class="fa-solid fa-phone"></i
                  ><a href="tel:${phone}">${phone}</a>
                </li>`
              : ""}
            <li><i class="fa-solid fa-location-dot"></i>${APP.brand.org}</li>
          </ul>
        </div>
      </div>
    </div>`,
  )}`;
}

export function init(root) {
  const form = root.querySelector("#contactForm");
  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      subject: [rules.required()],
      message: [rules.required(), rules.min(20)],
    },
    async onSubmit(values) {
      await sendContactMessage(values);
      form.reset();
      await notify.success(
        "Message sent",
        "Thanks for reaching out — we’ll reply by email soon.",
      );
    },
  });
}
