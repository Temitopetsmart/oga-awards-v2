import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { go } from "../../../shared/js/core/nav.js";
import { store } from "../../../shared/js/core/store.js";
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
  findNomineeById,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

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

/**
 * Nominee dashboard. With ?viewAs=<nominee id> a signed-in admin sees the page exactly as that
 * nominee does, read-only (DEMO BRIDGE: the real backend will check the admin's permission).
 */
export default async function dashboard(ctx, { params }) {
  const viewAsId = params.get("viewAs");
  if (viewAsId && !store.get("admin:session")) {
    return notice({
      icon: "fa-user-lock",
      title: "Admin sign-in needed",
      text: "Sign in to the admin to preview a nominee’s dashboard.",
      actions: [{ label: "Admin sign-in", href: "admin/login.html" }],
    });
  }
  const me = viewAsId ? findNomineeById(viewAsId) : currentNominee();
  if (!me) {
    if (viewAsId)
      return notice({
        icon: "fa-user-slash",
        title: "Nominee not found",
        actions: [{ label: "Back", href: "index.html" }],
      });
    go("login.html");
    return null;
  }
  const readOnly = Boolean(viewAsId);
  const cat = (await getCategories(me.occasionId ?? ctx.occasion.id)).find(
    (c) => c.id === me.categoryId,
  );
  const st = STATUS[me.status] ?? STATUS.pending;
  const profileUrl = `${ROOT}nominee.html?id=${encodeURIComponent(me.id)}`;

  return html` ${readOnly
    ? html`<div class="alert alert-oga m-0 rounded-0 text-center" role="status">
        <i class="fa-solid fa-eye me-2"></i>Admin preview — you’re seeing
        ${me.name}’s dashboard. Read-only.
      </div>`
    : ""}
  ${pageHero({
    eyebrow: "Nominee dashboard",
    title: `Hello, ${me.name.split(" ")[0]}`,
    crumbs: [{ label: "Dashboard" }],
  })}
  ${pageBody(
    html` <div class="row g-4">
      <div class="col-lg-4">
        <aside class="oga-card text-center align-items-center">
          ${avatar(me, true)}
          <h2 class="h5 mt-3">${me.name}</h2>
          <p>${[me.department, me.level].filter(Boolean).join(" · ")}</p>
          <span class="oga-badge mt-2">${st.label}</span>
          <p class="mt-3 small">${st.text}</p>
          ${readOnly
            ? ""
            : html`<button class="btn btn-ghost btn-sm mt-auto" id="logoutBtn">
                <i class="fa-solid fa-right-from-bracket me-2"></i>Log out
              </button>`}
        </aside>
      </div>
      <div class="col-lg-8">
        <div class="oga-card mb-4">
          <h2 class="h5 mt-0">Your category</h2>
          <p class="mb-0">${cat?.name ?? "—"}</p>
        </div>
        ${me.status === "approved"
          ? html` <div class="oga-card mb-4">
              <h2 class="h5 mt-0">Your public profile</h2>
              <div class="input-group mt-2">
                <input
                  class="form-control"
                  id="profileUrl"
                  readonly
                  value="${profileUrl}"
                /><button class="btn btn-gold" id="copyBtn" type="button">
                  Copy link
                </button>
              </div>
            </div>`
          : ""}
        <form id="profileForm" class="oga-formcard" novalidate>
          <fieldset ${readOnly ? "disabled" : ""}>
            <h2 class="h5 mb-3">Edit profile</h2>
            <div class="row">
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
                required: !readOnly,
                value: me.phone ?? "",
                col: "col-md-6",
              })}
            </div>
            ${field({
              label: "Department",
              name: "department",
              required: true,
              value: me.department ?? "",
            })}
            ${field({
              label: "About you",
              name: "bio",
              type: "textarea",
              rows: 5,
              value: me.bio ?? "",
              help: "Shown on your public profile once approved (max 500 characters).",
            })}
            ${readOnly
              ? ""
              : html`<button class="btn btn-gold" type="submit">
                  Save changes
                </button>`}
          </fieldset>
        </form>
      </div>
    </div>`,
  )}`;
}

export function init(root, ctx, { params }) {
  if (params.get("viewAs")) return; // read-only admin preview
  root.querySelector("#logoutBtn")?.addEventListener("click", async () => {
    if (await notify.confirm({ title: "Log out?", confirmText: "Log out" })) {
      logoutNominee();
      go("login.html");
    }
  });
  root.querySelector("#copyBtn")?.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(
        root.querySelector("#profileUrl").value,
      );
      notify.toast("success", "Link copied");
    } catch {
      root.querySelector("#profileUrl").select();
    }
  });
  const form = root.querySelector("#profileForm");
  if (form)
    bindForm(form, {
      schema: {
        name: [rules.required(), rules.min(2)],
        phone: [rules.required(), rules.phone()],
        department: [rules.required()],
        bio: [rules.max(500)],
      },
      async onSubmit(v) {
        await updateNominee({
          name: v.name.trim(),
          phone: v.phone.trim(),
          department: v.department.trim(),
          bio: v.bio.trim(),
        });
        notify.toast("success", "Profile saved");
      },
    });
}
