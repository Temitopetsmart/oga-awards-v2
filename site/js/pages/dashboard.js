import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { go } from "../../../shared/js/core/nav.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { getCategories } from "../../../shared/js/services/catalog.service.js";
import {
  currentNominee,
  updateNominee,
  logoutNominee,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "My Dashboard";

const STATUS = {
  pending: {
    label: "Pending review",
    text: "Our team is reviewing your entry. You’ll appear publicly once approved.",
  },
  approved: {
    label: "Approved",
    text: "You’re approved! Share your profile and ask supporters to vote when voting opens.",
  },
  rejected: {
    label: "Not approved",
    text: "Your entry wasn’t approved. Contact the organisers for details.",
  },
};

export default async function dashboard(ctx) {
  const me = currentNominee();
  if (!me) {
    go("login.html");
    return null;
  }

  const cat = (await getCategories(ctx.occasion.id)).find(
    (c) => c.id === me.categoryId,
  );
  const st = STATUS[me.status] ?? STATUS.pending;
  const profileUrl = `${ROOT}nominee.html?id=${encodeURIComponent(me.id)}`;

  return html`
    ${pageHero({
      eyebrow: "Nominee dashboard",
      title: `Hello, ${me.name.split(" ")[0]}`,
      crumbs: [{ label: "Dashboard" }],
    })}
    ${pageBody(
      html`
        <div class="row g-4 align-items-start">
          <div class="col-lg-4">
            <aside class="oga-card text-center align-items-center">
              ${avatar(me, true)}
              <h2 class="h5 mt-3" id="nomineeName">${me.name}</h2>
              <p id="nomineeDepartment">${me.department} · ${me.level}</p>
              <span class="oga-badge mt-3">${st.label}</span>
              <p class="mt-3 small">${st.text}</p>
              <button
                class="btn btn-gold btn-sm w-100 mt-3"
                id="editProfileBtn"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#editProfileModal"
              >
                <i class="fa-solid fa-user-pen me-2"></i>Edit profile
              </button>
              <button class="btn btn-ghost btn-sm w-100 mt-2" id="logoutBtn" type="button">
                <i class="fa-solid fa-right-from-bracket me-2"></i>Log out
              </button>
            </aside>
          </div>

          <div class="col-lg-8">
            <section class="oga-card mb-4">
              <div class="d-flex align-items-center gap-3">
                <span class="oga-icon"><i class="fa-solid fa-award"></i></span>
                <div>
                  <h2 class="h5 mt-0 mb-1">Your category</h2>
                  <p class="mb-0">${cat?.name ?? "Not assigned"}</p>
                </div>
              </div>
            </section>

            ${me.status === "approved"
              ? html`
                  <section class="oga-card">
                    <div class="d-flex align-items-center gap-3 mb-3">
                      <span class="oga-icon"><i class="fa-solid fa-link"></i></span>
                      <div>
                        <h2 class="h5 mt-0 mb-1">Your public profile</h2>
                        <p class="small">Share your profile with supporters.</p>
                      </div>
                    </div>
                    <div class="input-group">
                      <input class="form-control" id="profileUrl" readonly value="${profileUrl}" aria-label="Public profile link">
                      <button class="btn btn-gold" id="copyBtn" type="button">Copy link</button>
                    </div>
                    <a class="btn btn-ghost btn-sm mt-3" href="${profileUrl}" target="_blank" rel="noopener">
                      <i class="fa-solid fa-arrow-up-right-from-square me-2"></i>View public profile
                    </a>
                  </section>
                `
              : html`
                  <section class="oga-card">
                    <div class="d-flex align-items-center gap-3">
                      <span class="oga-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                      <div>
                        <h2 class="h5 mt-0 mb-1">Application status</h2>
                        <p class="mb-0">${st.text}</p>
                      </div>
                    </div>
                  </section>
                `}
          </div>
        </div>

        <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileTitle" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <div>
                  <h2 class="modal-title h5" id="editProfileTitle">Edit profile</h2>
                  <p class="small text-secondary mb-0">Keep your nominee information up to date.</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <form id="profileForm" novalidate>
                <div class="modal-body">
                  <div class="row g-3">
                    ${field({
                      label: "Full name",
                      name: "name",
                      required: true,
                      value: me.name,
                      col: "col-md-6",
                    })}
                    ${field({
                      label: "Phone",
                      name: "phone",
                      type: "tel",
                      required: true,
                      value: me.phone,
                      col: "col-md-6",
                    })}
                    ${field({
                      label: "Department",
                      name: "department",
                      required: true,
                      value: me.department,
                      col: "col-12",
                    })}
                    ${field({
                      label: "About you",
                      name: "bio",
                      type: "textarea",
                      rows: 4,
                      value: me.bio,
                      help: "Shown on your public profile once approved (max 500 characters).",
                      col: "col-12",
                    })}
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-gold" type="submit">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Save changes
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      `,
    )}
  `;
}

export function init(root) {
  root.querySelector("#logoutBtn")?.addEventListener("click", async () => {
    if (await notify.confirm({ title: "Log out?", confirmText: "Log out" })) {
      logoutNominee();
      go("login.html");
    }
  });

  root.querySelector("#copyBtn")?.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(root.querySelector("#profileUrl").value);
      notify.toast("success", "Link copied");
    } catch {
      const input = root.querySelector("#profileUrl");
      input?.select();
      input?.setSelectionRange(0, input.value.length);
    }
  });

  const form = root.querySelector("#profileForm");
  if (!form) return;

  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      phone: [rules.required(), rules.phone()],
      department: [rules.required()],
      bio: [rules.max(500)],
    },
    async onSubmit(v) {
      const updated = {
        name: v.name.trim(),
        phone: v.phone.trim(),
        department: v.department.trim(),
        bio: v.bio.trim(),
      };

      await updateNominee(updated);

      const name = root.querySelector("#nomineeName");
      const department = root.querySelector("#nomineeDepartment");
      if (name) name.textContent = updated.name;
      if (department) department.textContent = `${updated.department} · ${currentNominee()?.level ?? ""}`;

      const modal = root.querySelector("#editProfileModal");
      if (window.bootstrap?.Modal && modal) {
        window.bootstrap.Modal.getOrCreateInstance(modal).hide();
      }

      notify.toast("success", "Profile saved");
    },
  });
}
