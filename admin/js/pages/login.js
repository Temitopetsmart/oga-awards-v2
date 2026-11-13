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
  restoreSession,
  requestPasswordReset,
} from "../services/admin-auth.service.js";

export const title = "Sign in";

export default async function login() {
  restoreSession(); // try to restore the session in case the user is already signed in
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

      <p class="text-center mt-3 mb-0">
        <a href="#" id="forgotLink">Forgot your password?</a>
      </p>
      <form id="forgotForm" class="mt-3" novalidate hidden>
        <p class="small">
          Enter your admin email. If it is an admin account, we’ll send a link
          to choose a new password.
        </p>
        ${field({
          label: "Email",
          name: "forgotEmail",
          type: "email",
          required: true,
          autocomplete: "username",
        })}
        <button class="btn btn-ghost w-100" type="submit">
          Send the reset link
        </button>
      </form>

      <p class="text-center mt-4 mb-0">
        <a href="${ROOT}">← Back to ${APP.brand.name}</a>
      </p>
    </div>
  </div>`;
}

export function init(root) {
  const forgot = root.querySelector("#forgotForm");
  root.querySelector("#forgotLink").addEventListener("click", (e) => {
    e.preventDefault();
    forgot.hidden = !forgot.hidden;
    if (!forgot.hidden) forgot.elements.forgotEmail.focus();
  });
  bindForm(forgot, {
    schema: { forgotEmail: [rules.required(), rules.email()] },
    async onSubmit(v) {
      const r = await requestPasswordReset(v.forgotEmail.trim());
      await notify.success("Check your email", r.message);
      forgot.hidden = true;
    },
  });
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
