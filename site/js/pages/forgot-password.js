import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  requestPasswordReset,
  resetNomineePassword,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "Reset Password";

export default function forgotPassword() {
  return html`
    ${pageHero({
      eyebrow: "Account help",
      title: "Reset your password",
      lead: "Recover access to your nominee account.",
      crumbs: [{ label: "Reset password" }],
    })}
    ${pageBody(
      html`
        <div class="oga-formcard">
          <form id="requestResetForm" novalidate>
            <h2 class="h5">Request a reset token</h2>
            ${field({
              label: "Account email",
              name: "email",
              type: "email",
              required: true,
              autocomplete: "email",
            })}
            <button class="btn btn-gold w-100" type="submit">
              Request reset
            </button>
          </form>

          <div id="resetDemoNotice" class="alert alert-warning mt-3" hidden>
            <strong>Demo mode:</strong> no email was sent. Use this temporary
            token to test the reset form:
            <code id="resetDemoToken"></code>
            <p class="small mb-0 mt-2">
              In production, this token must be delivered privately by email.
            </p>
          </div>

          <hr class="my-4" />

          <form id="completeResetForm" novalidate>
            <h2 class="h5">Choose a new password</h2>
            ${field({
              label: "Account email",
              name: "email",
              type: "email",
              required: true,
              autocomplete: "email",
            })}
            ${field({
              label: "Reset token",
              name: "token",
              required: true,
            })}
            ${field({
              label: "New password",
              name: "password",
              type: "password",
              required: true,
              autocomplete: "new-password",
            })}
            ${field({
              label: "Confirm new password",
              name: "confirm",
              type: "password",
              required: true,
              autocomplete: "new-password",
            })}
            <button class="btn btn-gold w-100" type="submit">
              Set new password
            </button>
          </form>

          <p class="text-center mt-3 mb-0">
            Remembered it? <a href="login.html">Return to login</a>
          </p>
        </div>
      `,
      { width: "32rem" },
    )}
  `;
}

export function init(root) {
  const requestForm = root.querySelector("#requestResetForm");
  const completeForm = root.querySelector("#completeResetForm");
  const notice = root.querySelector("#resetDemoNotice");
  const tokenOutput = root.querySelector("#resetDemoToken");

  bindForm(requestForm, {
    schema: {
      email: [rules.required(), rules.email()],
    },
    async onSubmit(values) {
      const result = await requestPasswordReset(values.email);

      tokenOutput.textContent = result.token;
      notice.hidden = false;

      completeForm.elements.email.value = values.email;
      completeForm.elements.token.value = result.token;

      await notify.success(
        "Reset token created",
        "Demo mode only: use the temporary token shown on this page.",
      );
    },
  });

  bindForm(completeForm, {
    schema: {
      email: [rules.required(), rules.email()],
      token: [rules.required()],
      password: [rules.required(), rules.min(8)],
      confirm: [
        rules.required(),
        rules.match("password", "Passwords do not match."),
      ],
    },
    async onSubmit(values) {
      await resetNomineePassword({
        email: values.email,
        token: values.token,
        password: values.password,
      });

      await notify.success(
        "Password updated",
        "You can now log in using your new password.",
      );

      location.href = "login.html";
    },
  });
}
