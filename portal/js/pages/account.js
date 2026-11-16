import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { go } from "../../../shared/js/core/nav.js";
import {
  requestEmailVerification,
  verifyNomineeEmail,
  changeNomineePassword,
  logoutNominee,
} from "../../../shared/js/services/auth.service.js";
import { card } from "../ui.js";

export const title = "Account";

export default function account(p) {
  const { me, readOnly } = p;
  return html`<div class="row g-4">
    <div class="col-lg-6 d-grid gap-4 align-content-start">
      ${card(
        "Sign-in and email",
        html`<div class="pt-kv mb-3">
            <small>Email</small><strong>${me.email}</strong>
          </div>
          <div class="pt-kv mb-3">
            <small>Level</small
            ><strong>${me.accountLevel ?? me.level ?? "Not provided"}</strong>
          </div>
          ${me.matricNo
            ? html`<div class="pt-kv mb-3">
                <small>Matric number</small><strong>${me.matricNo}</strong>
              </div>`
            : ""}
          ${me.emailVerified
            ? html`<span class="pt-chip pt-chip--ok"
                ><i class="fa-solid fa-circle-check me-1"></i>Email
                verified</span
              >`
            : readOnly
              ? html`<span class="pt-chip pt-chip--wait"
                  >Email not verified</span
                >`
              : html`<p class="small text-secondary">
                    Your email isn’t verified yet. We’ll email you a code.
                  </p>
                  <button
                    class="btn btn-ghost btn-sm"
                    id="verifyEmailBtn"
                    type="button"
                  >
                    Email me a code
                  </button>
                  <div id="verifyPanel" class="mt-3" hidden>
                    <p class="small text-secondary" id="verifyNote"></p>
                    <form id="verifyEmailForm" novalidate>
                      ${field({
                        label: "Code from your email",
                        name: "token",
                        required: true,
                      })}
                      <button class="btn btn-gold btn-sm" type="submit">
                        Confirm email
                      </button>
                    </form>
                  </div>`}`,
        { icon: "fa-envelope" },
      )}
      ${readOnly
        ? ""
        : card(
            "Sign out",
            html`<p class="small text-secondary">
                You’ll need your email and password to come back.
              </p>
              <button class="btn btn-ghost" id="signOut" type="button">
                <i class="fa-solid fa-right-from-bracket me-2"></i>Sign out
              </button>`,
            { icon: "fa-door-open" },
          )}
    </div>
    <div class="col-lg-6">
      ${readOnly
        ? ""
        : card(
            "Change password",
            html`<form id="changePasswordForm" novalidate>
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
              <button class="btn btn-gold" type="submit">
                Update password
              </button>
            </form>`,
            { icon: "fa-key" },
          )}
    </div>
  </div>`;
}

export function init(root, p) {
  if (p.readOnly) return;
  root.querySelector("#signOut")?.addEventListener("click", async () => {
    if (await notify.confirm({ title: "Sign out?", confirmText: "Sign out" })) {
      logoutNominee();
      go("login");
    }
  });
  root.querySelector("#verifyEmailBtn")?.addEventListener("click", async () => {
    try {
      const r = await requestEmailVerification();
      if (r.alreadyVerified)
        return notify.info(
          "Already verified",
          "Your email is already verified.",
        );
      root.querySelector("#verifyNote").textContent = r.devOnly
        ? `Local test server: no email is sent. Your code is ${r.token}.`
        : `We’ve emailed a code to ${p.me.email}. It expires in ${r.expiresInMinutes ?? 30} minutes.`;
      root.querySelector("#verifyPanel").hidden = false;
    } catch (err) {
      notify.failure(err);
    }
  });
  const verify = root.querySelector("#verifyEmailForm");
  if (verify)
    bindForm(verify, {
      schema: { token: [rules.required()] },
      async onSubmit(v) {
        await verifyNomineeEmail(v.token);
        await notify.success(
          "Email verified",
          "Your account is now marked as verified.",
        );
        location.reload();
      },
    });
  const pw = root.querySelector("#changePasswordForm");
  if (pw)
    bindForm(pw, {
      schema: {
        currentPassword: [rules.required()],
        newPassword: [rules.required(), rules.min(8)],
        confirmPassword: [
          rules.required(),
          rules.match("newPassword", "Passwords do not match."),
        ],
      },
      async onSubmit(v) {
        await changeNomineePassword({
          currentPassword: v.currentPassword,
          newPassword: v.newPassword,
        });
        await notify.success(
          "Password updated",
          "Your password has been changed successfully.",
        );
        pw.reset();
      },
    });
}
