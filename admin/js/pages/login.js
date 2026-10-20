import { APP, ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  currentAdmin,
  loginAdmin,
  landingPage,
  seedDemo,
  DEMO_ACCOUNTS,
} from "../services/admin-auth.service.js";

export const title = "Sign in";

export default async function login() {
  await seedDemo();
  const me = currentAdmin();
  if (me && landingPage(me)) {
    location.replace(ROOT + landingPage(me));
    return html``;
  }
  return html` <div class="oga-login">
    <div class="oga-login__card">
      <div class="oga-login__brand"><em>OGA</em> Admin</div>
      <p class="mb-4">Sign in to manage the awards.</p>
      <form id="loginForm" novalidate>
        ${field({
          label: "Email",
          name: "email",
          type: "email",
          required: true,
          autocomplete: "username",
        })}
        ${field({
          label: "Password",
          name: "password",
          type: "password",
          required: true,
          autocomplete: "current-password",
        })}
        <button class="btn btn-gold btn-lg w-100" type="submit">Sign in</button>
      </form>
      <div class="oga-demo">
        <strong>Demo mode.</strong> Use any password with one of:
        <ul class="mb-0 mt-1 ps-3">
          ${DEMO_ACCOUNTS.map(
            (a) => html`<li><code>${a.email}</code> — ${a.label}</li>`,
          )}
        </ul>
      </div>
      <p class="text-center mt-4 mb-0">
        <a href="${ROOT}index.html">← Back to ${APP.brand.name}</a>
      </p>
    </div>
  </div>`;
}

export function init(root) {
  bindForm(root.querySelector("#loginForm"), {
    schema: {
      email: [rules.required(), rules.email()],
      password: [rules.required()],
    },
    async onSubmit(values) {
      const person = await loginAdmin(values);
      const next = landingPage(person);
      if (!next) {
        await notify.warning(
          "No pages yet",
          "You don’t have access to any pages right now.",
        );
        return;
      }
      location.assign(ROOT + next);
    },
  });
}
