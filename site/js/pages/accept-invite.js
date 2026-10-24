import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { userError } from "../../../shared/js/core/store.js";
import { APP } from "../../../shared/js/config/app.config.js";

export const title = "Accept admin invitation";

const token = () => new URLSearchParams(location.search).get("token");

/** Talks to the public invitation endpoints. The token in the link is the secret. */
async function call(path, options) {
  const res = await fetch(`${APP.data.apiBase}${path}`, options);
  let data = null;
  try {
    data = await res.json();
  } catch {
    /* not JSON */
  }
  if (!res.ok)
    throw userError(data?.error ?? "Something went wrong. Please try again.");
  return data;
}

let info = null;

export default async function acceptInvite() {
  const t = token();
  info = t
    ? await call(`/invite/${encodeURIComponent(t)}`).catch(() => null)
    : null;
  if (!info?.valid) {
    return html`
      <section class="container py-5">
        <div class="oga-card">
          <h1 class="h3">Invitation unavailable</h1>
          <p>
            This link is invalid, expired, revoked, or already used. Contact the
            administrator who invited you.
          </p>
        </div>
      </section>
    `;
  }
  return html`
    <section class="container py-5">
      <div class="oga-card mx-auto" style="max-width:620px">
        <p class="text-uppercase small">OGA Awards Admin</p>
        <h1 class="h3">Accept your invitation</h1>
        <p>
          You have been invited to access
          <strong>${info.editionName} ${info.editionLabel}</strong>.
        </p>
        <p class="small">Invited email: <strong>${info.email}</strong></p>
        <p class="small">
          This invitation expires ${new Date(info.expiresAt).toLocaleString()}.
        </p>
        <form id="acceptInviteForm" class="oga-formcard" novalidate>
          ${field({
            label: "Full name",
            name: "name",
            required: true,
            autocomplete: "name",
          })}
          ${field({
            label: "Email",
            name: "email",
            type: "email",
            required: true,
            value: info.email,
            autocomplete: "email",
          })}
          ${info.needsPassword
            ? html`
                ${field({
                  label: "Choose a password (at least 10 characters)",
                  name: "password",
                  type: "password",
                  required: true,
                  autocomplete: "new-password",
                })}
                ${field({
                  label: "Repeat the password",
                  name: "confirm",
                  type: "password",
                  required: true,
                  autocomplete: "new-password",
                })}
              `
            : html`<p class="small text-secondary">
                You already have an admin account — sign in with your existing
                password after accepting.
              </p>`}
          <button class="btn btn-gold w-100" type="submit">
            Accept invitation
          </button>
        </form>
      </div>
    </section>
  `;
}

export async function init(root) {
  const form = root.querySelector("#acceptInviteForm");
  if (!form || !info?.valid) return;
  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      ...(info.needsPassword
        ? {
            password: [rules.required(), rules.min(10)],
            confirm: [rules.required()],
          }
        : {}),
    },
    async onSubmit(values) {
      if (info.needsPassword && values.password !== values.confirm)
        throw userError("The two passwords do not match.");
      await call("/invite/accept", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          token: token(),
          name: values.name,
          email: values.email,
          password: values.password ?? "",
        }),
      });
      await notify.success(
        "Invitation accepted",
        info.needsPassword
          ? "Your admin account is ready. Sign in with your email and the password you just chose."
          : "Access added. Sign in with your existing password.",
      );
      location.assign("admin/login.html");
    },
  });
}
