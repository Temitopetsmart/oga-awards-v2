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
  listMyEntries,
  switchNomineeEntry,
  createNomineeEntry,
  requestEmailVerification,
  verifyNomineeEmail,
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

const LEVELS = ["ND I", "ND II", "HND I", "HND II"].map((level) => ({
  value: level,
  label: level,
}));

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
    if (viewAsId) {
      return notice({
        icon: "fa-user-slash",
        title: "Nominee not found",
        actions: [{ label: "Back", href: "index.html" }],
      });
    }

    go("login.html");
    return null;
  }

  const readOnly = Boolean(viewAsId);
  const categories = await getCategories(ctx.occasion.id);

  const cat = (await getCategories(me.occasionId ?? ctx.occasion.id)).find(
    (item) => item.id === me.categoryId,
  );

  const entries = readOnly ? [] : listMyEntries();

  const hasCurrentEntry = entries.some(
    (entry) => entry.occasionId === ctx.occasion.id,
  );

  const canApply = !readOnly && ctx.state === "nominations" && !hasCurrentEntry;

  const status = STATUS[me.status] ?? STATUS.pending;
  const profileUrl = `${ROOT}nominee.html?id=${encodeURIComponent(me.id)}`;

  return html`
    ${readOnly
      ? html`
          <div class="alert alert-oga m-0 rounded-0 text-center">
            <i class="fa-solid fa-eye me-2"></i>
            Admin preview — ${me.name}’s dashboard is read-only.
          </div>
        `
      : ""}
    ${pageHero({
      eyebrow: "Nominee dashboard",
      title: `Hello, ${me.name.split(" ")[0]}`,
      lead: "Manage your nominee entry and account.",
      crumbs: [{ label: "Dashboard" }],
    })}
    ${pageBody(html`
      ${!readOnly && entries.length > 1
        ? html`
            <div class="oga-card mb-4">
              <h2 class="h5 mt-0">Your edition entries</h2>
              <p class="small text-secondary">
                Your account can have a separate entry in each edition.
                Switching entries does not delete or change the others.
              </p>

              <label class="form-label" for="entrySwitcher">
                Select an entry
              </label>

              <select class="form-select" id="entrySwitcher">
                ${entries.map(
                  (entry) => html`
                    <option
                      value="${entry.id}"
                      ${entry.id === me.id ? "selected" : ""}
                    >
                      ${entry.occasionId} — ${entry.status}
                    </option>
                  `,
                )}
              </select>
            </div>
          `
        : ""}
      ${!readOnly
        ? html`
            <div class="oga-card mb-4">
              <h2 class="h5 mt-0">Account verification</h2>

              <p class="mb-2">
                Email:
                <strong>${me.email}</strong>
              </p>

              ${me.emailVerified
                ? html`<span class="oga-badge">Email verified</span>`
                : html`
                    <p class="small text-secondary">
                      Your email has not been verified yet.
                    </p>

                    <button
                      class="btn btn-ghost btn-sm"
                      id="verifyEmailBtn"
                      type="button"
                    >
                      Verify email (demo)
                    </button>

                    <div id="verifyPanel" class="mt-3" hidden>
                      <p class="small text-secondary">
                        Demo only — no email is sent. Use the temporary token
                        shown below to verify this account.
                      </p>

                      <p>
                        Token:
                        <code id="verifyToken"></code>
                      </p>

                      <form id="verifyEmailForm" novalidate>
                        ${field({
                          label: "Verification token",
                          name: "token",
                          required: true,
                        })}

                        <button class="btn btn-gold btn-sm" type="submit">
                          Confirm email
                        </button>
                      </form>
                    </div>
                  `}

              <p class="small text-secondary mt-3 mb-0">
                Change password:
                <a href="change-password.html">Update your password</a>
              </p>
            </div>
          `
        : ""}
      ${canApply
        ? html`
            <div class="oga-card mb-4">
              <h2 class="h5 mt-0">
                Apply for ${ctx.occasion.name} ${ctx.occasion.edition}
              </h2>

              <p class="small text-secondary">
                We’ll reuse your account and profile details, but create a new
                entry for this edition.
              </p>

              <form id="newEntryForm" novalidate>
                ${field({
                  label: "Category",
                  name: "categoryId",
                  options: categories.map((item) => ({
                    value: item.id,
                    label: item.name,
                  })),
                  required: true,
                })}

                <button class="btn btn-gold" type="submit">
                  Create edition entry
                </button>
              </form>
            </div>
          `
        : ""}

      <div class="row g-4">
        <div class="col-lg-4">
          <aside class="oga-card text-center align-items-center">
            ${avatar(me, true)}

            <h2 class="h5 mt-3">${me.name}</h2>

            <p>${[me.department, me.level].filter(Boolean).join(" · ")}</p>

            <span class="oga-badge mt-2">${status.label}</span>

            <p class="mt-3 small">${status.text}</p>

            ${readOnly
              ? ""
              : html`
                  <button
                    class="btn btn-ghost btn-sm mt-auto"
                    id="logoutBtn"
                    type="button"
                  >
                    <i class="fa-solid fa-right-from-bracket me-2"></i>
                    Log out
                  </button>
                `}
          </aside>
        </div>

        <div class="col-lg-8">
          <div class="oga-card mb-4">
            <h2 class="h5 mt-0">Your category</h2>
            <p class="mb-0">${cat?.name ?? "—"}</p>
          </div>

          ${me.status === "approved"
            ? html`
                <div class="oga-card mb-4">
                  <h2 class="h5 mt-0">Your public profile</h2>

                  <div class="row g-2 mt-2">
                    <div class="col-12 col-md-8">
                      <input
                        class="form-control w-100"
                        id="profileUrl"
                        readonly
                        value="${profileUrl}"
                        aria-label="Your public profile link"
                      />
                    </div>

                    <div class="col-12 col-md-4">
                      <button
                        class="btn btn-gold w-100"
                        id="copyBtn"
                        type="button"
                      >
                        <i class="fa-solid fa-copy me-2"></i>
                        Copy profile link
                      </button>
                    </div>
                  </div>
                </div>
              `
            : ""}

          <div class="oga-card">
            <div class="d-flex flex-wrap align-items-center gap-3">
              <div class="flex-grow-1">
                <h2 class="h5 mb-1">Personal information</h2>
                <p class="small text-secondary mb-0">
                  Keep your nominee details up to date.
                </p>
              </div>

              ${readOnly
                ? ""
                : html`
                    <button
                      class="btn btn-gold"
                      type="button"
                      data-bs-toggle="modal"
                      data-bs-target="#editProfileModal"
                    >
                      <i class="fa-solid fa-user-pen me-2"></i>
                      Edit profile
                    </button>
                  `}
            </div>

            <hr class="my-3" />

            <div class="row g-3">
              <div class="col-sm-6">
                <small class="text-secondary d-block">Full name</small>
                <strong class="text-break">${me.name}</strong>
              </div>

              <div class="col-sm-6">
                <small class="text-secondary d-block">Email address</small>
                <strong class="text-break">${me.email}</strong>
              </div>

              <div class="col-sm-6">
                <small class="text-secondary d-block">Phone number</small>
                <strong class="text-break"
                  >${me.phone || "Not provided"}</strong
                >
              </div>

              <div class="col-sm-6">
                <small class="text-secondary d-block">Department</small>
                <strong class="text-break"
                  >${me.department || "Not provided"}</strong
                >
              </div>

              <div class="col-sm-6">
                <small class="text-secondary d-block">Level</small>
                <strong>${me.level || "Not provided"}</strong>
              </div>
            </div>
          </div>

          ${!readOnly
            ? html`
                <div
                  class="modal fade"
                  id="editProfileModal"
                  tabindex="-1"
                  aria-labelledby="editProfileModalLabel"
                  aria-hidden="true"
                >
                  <div
                    class="modal-dialog modal-dialog-centered modal-dialog-scrollable"
                  >
                    <div class="modal-content">
                      <form id="profileForm" novalidate>
                        <div class="modal-header">
                          <div>
                            <h2
                              class="modal-title h5"
                              id="editProfileModalLabel"
                            >
                              Edit nominee profile
                            </h2>

                            <p class="small text-secondary mb-0">
                              Changes apply to your selected edition entry.
                            </p>
                          </div>

                          <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Close"
                          ></button>
                        </div>

                        <div class="modal-body p-3 p-md-4">
                          <div class="row justify-content-center">
  <div class="col-12 col-sm-10 col-md-8">
    <label
      class="form-label d-block mt-3"
      for="profilePhoto"
    >
      Profile photo
    </label>

    <input
      class="form-control w-100"
      type="file"
      id="profilePhoto"
      name="profilePhoto"
      accept="image/jpeg,image/png,image/webp"
    />

    <div class="form-text">
      Choose a clear JPG, PNG or WebP photo. Maximum size: 1 MB.
    </div>

    <div id="photoPreview" class="mt-3"></div>
  </div>
</div>
                          </div>

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
                              value: me.phone ?? "",
                              col: "col-md-6",
                            })}
                          </div>

                          <div class="row g-3">
                            ${field({
                              label: "Department",
                              name: "department",
                              required: true,
                              value: me.department ?? "",
                              col: "col-12 col-md-6",
                            })}
                            ${field({
                              label: "Level",
                              name: "level",
                              options: LEVELS,
                              required: true,
                              value: me.level ?? "",
                              col: "col-12 col-md-6",
                            })}
                            ${field({
                              label: "About you",
                              name: "bio",
                              type: "textarea",
                              rows: 4,
                              value: me.bio ?? "",
                              help: "Shown on your public profile after approval. Maximum 500 characters.",
                              col: "col-12",
                            })}
                          </div>
                        </div>

                        <div class="modal-footer">
                          <button
                            type="button"
                            class="btn btn-ghost"
                            data-bs-dismiss="modal"
                          >
                            Cancel
                          </button>

                          <button class="btn btn-gold" type="submit">
                            <i class="fa-solid fa-floppy-disk me-2"></i>
                            Save changes
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              `
            : ""}
        </div>
      </div>
    `)}
  `;
}

export function init(root, ctx, { params }) {
  if (params.get("viewAs")) return;

  root.querySelector("#logoutBtn")?.addEventListener("click", async () => {
    if (
      await notify.confirm({
        title: "Log out?",
        confirmText: "Log out",
      })
    ) {
      logoutNominee();
      go("login.html");
    }
  });

  root.querySelector("#entrySwitcher")?.addEventListener("change", (event) => {
    try {
      switchNomineeEntry(event.target.value);
      location.reload();
    } catch (error) {
      notify.failure(error);
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

  const profileForm = root.querySelector("#profileForm");
  const photoInput = root.querySelector("#profilePhoto");
  const photoPreview = root.querySelector("#photoPreview");

  let selectedPhoto = null;

  photoInput?.addEventListener("change", () => {
    selectedPhoto = null;

    if (photoPreview) {
      photoPreview.replaceChildren();
    }

    const file = photoInput.files?.[0];

    if (!file) return;

    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
    const maxSize = 1024 * 1024;

    if (!allowedTypes.includes(file.type)) {
      photoInput.value = "";
      notify.failure(new Error("Choose a JPG, PNG or WebP image."));
      return;
    }

    if (file.size > maxSize) {
      photoInput.value = "";
      notify.failure(new Error("Your photo must not exceed 1 MB."));
      return;
    }

    const reader = new FileReader();

    reader.onload = () => {
      selectedPhoto = String(reader.result);

      const image = document.createElement("img");
      image.src = selectedPhoto;
      image.alt = "New profile photo preview";
      image.className = "rounded-circle";
      image.style.width = "100px";
      image.style.height = "100px";
      image.style.objectFit = "cover";

      photoPreview?.replaceChildren(image);
    };

    reader.onerror = () => {
      selectedPhoto = null;
      photoInput.value = "";

      notify.failure(new Error("The selected image could not be read."));
    };

    reader.readAsDataURL(file);
  });

  if (profileForm) {
    bindForm(profileForm, {
      schema: {
        name: [rules.required(), rules.min(2)],
        phone: [rules.required(), rules.phone()],
        department: [rules.required()],
        level: [rules.required()],
        bio: [rules.max(500)],
      },

      async onSubmit(values) {
        try {
          await updateNominee({
            name: values.name.trim(),
            phone: values.phone.trim(),
            department: values.department.trim(),
            level: values.level,
            bio: values.bio.trim(),
            ...(selectedPhoto ? { photo: selectedPhoto } : {}),
          });

          const modalElement = root.querySelector("#editProfileModal");

          if (modalElement && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).hide();
          }

          await notify.success(
            "Profile saved",
            "Your profile has been updated.",
          );

          location.reload();
        } catch (error) {
          notify.failure(error);
        }
      },
    });
  }

  const newEntryForm = root.querySelector("#newEntryForm");

  if (newEntryForm) {
    bindForm(newEntryForm, {
      schema: {
        categoryId: [rules.required("Choose a category.")],
      },

      async onSubmit(values) {
        await createNomineeEntry({
          occasionId: ctx.occasion.id,
          categoryId: values.categoryId,
        });

        await notify.success(
          "New edition entry created",
          "Your account is unchanged. Your new entry is pending review.",
        );

        location.reload();
      },
    });
  }

  root.querySelector("#verifyEmailBtn")?.addEventListener("click", async () => {
    try {
      const result = await requestEmailVerification();

      if (result.alreadyVerified) {
        await notify.info(
          "Already verified",
          "Your email is already verified.",
        );
        return;
      }

      root.querySelector("#verifyToken").textContent = result.token;
      root.querySelector("#verifyPanel").hidden = false;
    } catch (error) {
      notify.failure(error);
    }
  });

  const verifyForm = root.querySelector("#verifyEmailForm");

  if (verifyForm) {
    bindForm(verifyForm, {
      schema: {
        token: [rules.required()],
      },

      async onSubmit(values) {
        await verifyNomineeEmail(values.token);

        await notify.success(
          "Email verified",
          "Your demo account is now marked as verified.",
        );

        location.reload();
      },
    });
  }
}
