import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  getInvitation,
  acceptInvitation,
} from "../../../admin/js/services/invitation.repo.js";
import {
  listPeople,
  addPerson,
  upsertAssignment,
  ensureTeam,
} from "../../../admin/js/services/team.repo.js";
import {
  ensureEditions,
  getEdition,
} from "../../../admin/js/services/edition.repo.js";

export const title = "Accept admin invitation";

export default async function acceptInvite() {
  await ensureEditions();
  await ensureTeam();

  const token = new URLSearchParams(location.search).get("token");
  const invitation = token ? getInvitation(token) : null;

  const valid =
    invitation &&
    invitation.status === "pending" &&
    new Date(invitation.expiresAt).getTime() > Date.now();

  if (!valid) {
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

  const edition = getEdition(invitation.editionId);

  return html`
    <section class="container py-5">
      <div class="oga-card mx-auto" style="max-width:620px">
        <p class="text-uppercase small">OGA Awards Admin</p>
        <h1 class="h3">Accept your invitation</h1>
        <p>
          You have been invited to access
          <strong
            >${edition?.name ?? "OGA Awards"} ${edition?.edition ?? ""}</strong
          >.
        </p>
        <p class="small">Invited email: <strong>${invitation.email}</strong></p>
        <p class="small">
          This invitation expires
          ${new Date(invitation.expiresAt).toLocaleString()}.
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
            value: invitation.email,
            autocomplete: "email",
          })}

          <button class="btn btn-gold w-100" type="submit">
            Accept invitation
          </button>
        </form>
      </div>
    </section>
  `;
}

export async function init(root) {
  const token = new URLSearchParams(location.search).get("token");
  const form = root.querySelector("#acceptInviteForm");

  if (!form) return;

  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
    },

    async onSubmit(values) {
      const details = acceptInvitation(token, values);
      const normalizedEmail = details.email.toLowerCase();

      let person = listPeople().find(
        (item) => item.email?.trim().toLowerCase() === normalizedEmail,
      );

      if (person && !person.active) {
        throw new Error(
          "This email belongs to an inactive admin account. Contact the administrator.",
        );
      }

      if (!person) {
        person = addPerson({
          name: details.name,
          email: details.email,
          active: true,
        });
      }

      upsertAssignment(person.id, details.editionId, {
        roleIds: details.roleIds,
        grants: [],
        denies: [],
      });

      await notify.success(
        "Invitation accepted",
        "Your demo admin access has been created. You can now open the admin login page.",
      );

      location.assign("admin/login.html");
    },
  });
}
