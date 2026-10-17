import { html } from "../../../shared/js/core/dom.js";
import { go } from "../../../shared/js/core/nav.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import {
  loginNominee,
  currentNominee,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "Nominee Login";

export default async function login() {
  if (currentNominee()) {
    go("dashboard.html");
    return null;
  }
  return html` ${pageHero({
    eyebrow: "Nominees",
    title: "Log in",
    lead: "Access your nominee dashboard.",
    crumbs: [{ label: "Login" }],
  })}
  ${pageBody(
    html` <form id="loginForm" class="oga-formcard" novalidate>
      ${field({
        label: "Email",
        name: "email",
        type: "email",
        required: true,
        autocomplete: "email",
      })}
      ${field({
        label: "Password",
        name: "password",
        type: "password",
        required: true,
        autocomplete: "current-password",
      })}
      <button class="btn btn-gold btn-lg w-100" type="submit">Log in</button>
      <p class="text-center mt-3 mb-0">
        New here? <a href="register.html">Register as a nominee</a>
      </p>
    </form>`,
    { width: "30rem" },
  )}`;
}

export function init(root) {
  bindForm(root.querySelector("#loginForm"), {
    schema: {
      email: [rules.required(), rules.email()],
      password: [rules.required()],
    },
    async onSubmit(values) {
      await loginNominee(values);
      go("dashboard.html");
    },
  });
}
