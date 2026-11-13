import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { userError } from "../../../shared/js/core/store.js";
import { APP, ROOT } from "../../../shared/js/config/app.config.js";

export const title = "Reset admin password";

const token = () => new URLSearchParams(location.search).get("token") ?? "";

/** The link's secret is in the address. The server checks it when the new password is sent. */
async function reset(password) {
  const res = await fetch(`${APP.data.apiBase}/admin-password/reset`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ token: token(), password }),
  });
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

export default async function adminReset() {
  if (!token()) {
    return html`<section class="container py-5">
      <div class="oga-card mx-auto" style="max-width:560px">
        <h1 class="h3">Link not valid</h1>
        <p>
          This reset link is missing or broken. Ask for a new one from the admin
          sign-in page.
        </p>
        <a class="btn btn-gold" href="${ROOT}admin/login">Go to sign in</a>
      </div>
    </section>`;
  }
  return html`<section class="container py-5">
    <div class="oga-card mx-auto" style="max-width:560px">
      <p class="text-uppercase small">OGA Awards Admin</p>
      <h1 class="h3">Choose a new password</h1>
      <form id="resetForm" novalidate>
        ${field({
          label: "New password",
          name: "password",
          type: "password",
          required: true,
          autocomplete: "new-password",
          help: "At least 10 characters.",
        })}
        ${field({
          label: "New password again",
          name: "again",
          type: "password",
          required: true,
          autocomplete: "new-password",
        })}
        <button class="btn btn-gold" type="submit">Save password</button>
      </form>
    </div>
  </section>`;
}

export function init(root) {
  const form = root.querySelector("#resetForm");
  if (!form) return;
  bindForm(form, {
    schema: {
      password: [
        rules.required(),
        rules.min(10, "Use at least 10 characters."),
      ],
      again: [
        rules.required(),
        rules.custom((v) =>
          v === form.elements.password.value
            ? null
            : "The two passwords are not the same.",
        ),
      ],
    },
    async onSubmit(v) {
      await reset(v.password);
      await notify.success(
        "Password changed",
        "You can sign in with your new password now.",
      );
      location.assign(`${ROOT}admin/login`);
    },
  });
}
