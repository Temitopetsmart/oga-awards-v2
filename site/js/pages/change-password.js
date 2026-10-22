import { html } from "../../../shared/js/core/dom.js";
import { go } from "../../../shared/js/core/nav.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  currentNominee,
  changeNomineePassword,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "Change Password";

export default function changePassword() {
  if (!currentNominee()) {
    go("login.html");
    return null;
  }

  return html`
    ${pageHero({
      eyebrow: "Account settings",
      title: "Change password",
      lead: "Choose a new password for your nominee account.",
      crumbs: [{ label: "Change password" }],
    })}
    ${pageBody(
      html`
        <form id="changePasswordForm" class="oga-formcard" novalidate>
          ${field({
            label: "Current password",
            name: "currentPassword",
            type: "password",
            required: true,
            autocomplete: "current-password",
          })}
          ${field({
            label: "New password",
            name: "newPassword",
            type: "password",
            required: true,
            autocomplete: "new-password",
            help: "Use at least 8 characters.",
          })}
          ${field({
            label: "Confirm new password",
            name: "confirmPassword",
            type: "password",
            required: true,
            autocomplete: "new-password",
          })}
          <button class="btn btn-gold w-100" type="submit">
            Update password
          </button>
          <p class="text-center mt-3 mb-0">
            <a href="dashboard.html">Back to dashboard</a>
          </p>
        </form>
      `,
      { width: "32rem" },
    )}
  `;
}

export function init(root) {
  const form = root.querySelector("#changePasswordForm");
  if (!form) return;

  bindForm(form, {
    schema: {
      currentPassword: [rules.required()],
      newPassword: [rules.required(), rules.min(8)],
      confirmPassword: [
        rules.required(),
        rules.match("newPassword", "Passwords do not match."),
      ],
    },
    async onSubmit(values) {
      await changeNomineePassword({
        currentPassword: values.currentPassword,
        newPassword: values.newPassword,
      });

      await notify.success(
        "Password updated",
        "Your password has been changed successfully.",
      );

      go("dashboard.html");
    },
  });
}
