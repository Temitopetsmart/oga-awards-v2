import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  changeMyPassword,
  signOutEverywhere,
  updateMyName,
} from "../services/account.api.js";

export const title = "My account";
export const crumbs = ["Administration", "My account"];

export default async function account() {
  return html`<div class="row g-4">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">
          <h2 class="card-title mb-0">Your details</h2>
        </div>
        <div class="card-body">
          <form id="nameForm" novalidate>
            ${field({
              label: "Full name",
              name: "name",
              required: true,
              value: "",
            })}
            ${field({
              label: "Email",
              name: "email",
              value: "",
              attrs: "disabled",
              help: "Only a Super admin can change the email you sign in with.",
            })}
            <button class="btn btn-gold" type="submit">Save name</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card mb-4">
        <div class="card-header">
          <h2 class="card-title mb-0">Change your password</h2>
        </div>
        <div class="card-body">
          <form id="passwordForm" novalidate>
            ${field({
              label: "Current password",
              name: "current",
              type: "password",
              required: true,
              autocomplete: "current-password",
            })}
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
            <p class="small text-secondary">
              Changing it signs you out on every other device. You stay signed
              in here.
            </p>
            <button class="btn btn-gold" type="submit">Change password</button>
          </form>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h2 class="card-title mb-0">Devices</h2></div>
        <div class="card-body">
          <p class="small text-secondary">
            Lost a phone or used a shared computer? Sign out of every other
            device. You stay signed in here.
          </p>
          <button class="btn btn-ghost" id="signOutAll" type="button">
            Sign out everywhere else
          </button>
        </div>
      </div>
    </div>
  </div>`;
}

export function init(root, { admin }) {
  const nameForm = root.querySelector("#nameForm");
  nameForm.elements.name.value = admin?.name ?? "";
  nameForm.elements.email.value = admin?.email ?? "";
  bindForm(nameForm, {
    schema: { name: [rules.required(), rules.min(2), rules.max(150)] },
    async onSubmit(v) {
      await updateMyName(v.name.trim());
      notify.toast("success", "Name saved");
      setTimeout(() => location.reload(), 600);
    },
  });

  const pwForm = root.querySelector("#passwordForm");
  bindForm(pwForm, {
    schema: {
      current: [rules.required()],
      password: [
        rules.required(),
        rules.min(10, "Use at least 10 characters."),
      ],
      again: [
        rules.required(),
        rules.custom((v) =>
          v === pwForm.elements.password.value
            ? null
            : "The two passwords are not the same.",
        ),
      ],
    },
    async onSubmit(v) {
      await changeMyPassword({ current: v.current, password: v.password });
      pwForm.reset();
      notify.toast(
        "success",
        "Password changed. Other devices were signed out.",
      );
    },
  });

  root.querySelector("#signOutAll").addEventListener("click", async () => {
    if (
      !(await notify.confirm({
        title: "Sign out of every other device?",
        text: "Anyone using your account elsewhere will have to sign in again.",
        confirmText: "Sign them out",
      }))
    )
      return;
    await signOutEverywhere();
    notify.toast("success", "Done");
  });
}
