import { ROOT, APP } from "../../../shared/js/config/app.config.js";
import { html, toHTML } from "../../../shared/js/core/dom.js";
import { go } from "../../../shared/js/core/nav.js";
import { store } from "../../../shared/js/core/store.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import {
  FLIER_SIZES,
  drawFlier,
  downloadFlier,
  loadQr,
} from "../../../shared/js/components/flier.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  categoryPicker,
  pickedCategories,
  bindCategoryPicker,
} from "../../../shared/js/components/category-picker.js";
import { normalizeMatric } from "../../../shared/js/core/matric.js";
import {
  getCategories,
  getWinners,
} from "../../../shared/js/services/catalog.service.js";
import {
  currentNominee,
  updateNominee,
  uploadNomineePhoto,
  logoutNominee,
  findNomineeById,
  saveMatric,
  listMyEntries,
  switchNomineeEntry,
  createNomineeEntry,
  confirmRegistration,
  requestEmailVerification,
  verifyNomineeEmail,
  resubmitNomineeEntry,
  getAccessPass,
  claimAccessPass,
  getNomineeAnnouncements,
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

export default async function dashboard(ctx, { params }) {
  const viewAsId = params.get("viewAs");

  // Back from paying the registration fee for a new edition: the server checks the payment before anything is created.
  if (params.get("pay") === "return" && params.get("tx_ref")) {
    const ref = params.get("tx_ref");
    const cancelled = params.get("status") === "cancelled";
    history.replaceState(null, "", location.pathname);
    if (cancelled) {
      notify.toast("info", "Payment cancelled. You have not been charged.");
    } else {
      try {
        await confirmRegistration(ref);
      } catch (err) {
        await notify.warning(
          "We couldn’t confirm your payment yet",
          err.message,
        );
      }
    }
  }

  if (viewAsId && !store.get("admin:me")) {
    return notice({
      icon: "fa-user-lock",
      title: "Admin sign-in needed",
      text: "Sign in to the admin to preview a nominee’s dashboard.",
      actions: [{ label: "Admin sign-in", href: "admin/login" }],
    });
  }

  const me = viewAsId ? await findNomineeById(viewAsId) : currentNominee();

  if (!me) {
    if (viewAsId) {
      return notice({
        icon: "fa-user-slash",
        title: "Nominee not found",
        actions: [{ label: "Back", href: "./" }],
      });
    }

    go("login");
    return null;
  }

  const readOnly = Boolean(viewAsId);
  const categories = await getCategories(ctx.occasion.id);

  const cat = (await getCategories(me.occasionId ?? ctx.occasion.id)).find(
    (item) => item.id === me.categoryId,
  );

  const entries = readOnly ? [] : listMyEntries();

  // Entries in this edition (one per category) and what is still open to this nominee.
  const mineHere = entries.filter(
    (entry) => entry.occasionId === ctx.occasion.id,
  );
  const takenIds = mineHere.map((entry) => String(entry.categoryId));
  const maxCategories = APP.voting.maxCategoriesPerNominee;
  const stillOpen = categories.filter(
    (item) =>
      !takenIds.includes(String(item.id)) &&
      (!item.eligibleLevels?.length ||
        item.eligibleLevels.includes(me.accountLevel ?? me.level)),
  );
  const canApply =
    !readOnly &&
    ctx.state === "nominations" &&
    mineHere.length < maxCategories &&
    stillOpen.length > 0;
  const feeDue =
    mineHere.length === 0 && Number(APP.voting.nomineeRegistrationFee) > 0;
  const catName = (entry) =>
    categories.find((item) => String(item.id) === String(entry.categoryId))
      ?.name;

  const status = STATUS[me.status] ?? STATUS.pending;
  const profileUrl = `${ROOT}nominee?id=${encodeURIComponent(me.id)}`;

  // Winners are only ever known here once the organisers have published them.
  const winners =
    ctx.state === "results" && me.occasionId === ctx.occasion.id
      ? await getWinners(ctx.occasion.id).catch(() => [])
      : [];
  const isWinner = winners.some((w) => String(w.nomineeId) === String(me.id));

  // How complete the public profile is.
  const checklist = [
    ["A profile photo", Boolean(me.photo)],
    ["A short bio", Boolean((me.bio ?? "").trim())],
    ["A phone number", Boolean((me.phone ?? "").trim())],
    ["A verified email", Boolean(me.emailVerified)],
  ];
  const done = checklist.filter(([, ok]) => ok).length;
  const percent = Math.round((done / checklist.length) * 100);

  const steps = [
    ["nominations", "Nominations"],
    ["voting", "Voting"],
    ["voting-closed", "Counting"],
    ["results", "Winners"],
  ];
  const at = Math.max(
    0,
    steps.findIndex(([key]) => key === ctx.state),
  );
  const shareText = `Vote for me, ${me.name}, in the ${ctx.occasion.name} ${ctx.occasion.edition}${cat ? ` (${cat.name})` : ""}!`;

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
                      ${[entry.occasionId, catName(entry), entry.status]
                        .filter(Boolean)
                        .join(" — ")}
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
                      Verify email
                    </button>

                    <div id="verifyPanel" class="mt-3" hidden>
                      <p class="small text-secondary">
                        Test server only: no email is sent. Use the temporary
                        token shown below to verify this account.
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
                <a href="change-password">Update your password</a>
              </p>
            </div>
          `
        : ""}
      ${canApply
        ? html`
            <div class="oga-card mb-4">
              <h2 class="h5 mt-0">
                ${mineHere.length === 0
                  ? html`Apply for ${ctx.occasion.name} ${ctx.occasion.edition}`
                  : "Enter another category"}
              </h2>

              <p class="small text-secondary">
                ${mineHere.length === 0
                  ? "We’ll reuse your account and profile details, but create a new entry for this edition."
                  : "Same profile and photo, no extra registration fee. Each category is reviewed and voted on separately."}
                ${feeDue
                  ? " The registration fee is paid once, however many categories you pick."
                  : ""}
              </p>

              <form id="newEntryForm" novalidate>
                ${categoryPicker({
                  categories,
                  taken: takenIds,
                  max: maxCategories,
                })}

                <button class="btn btn-gold" type="submit">
                  ${mineHere.length === 0
                    ? "Create edition entry"
                    : "Enter the selected categories"}
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
          ${me.status === "rejected"
            ? html`<div class="alert alert-warning mb-4" role="alert">
                <strong>This entry needs changes.</strong>
                ${me.reviewNote
                  ? html`<p class="mb-2 mt-1">
                      Reviewer’s note: ${me.reviewNote}
                    </p>`
                  : html`<p class="mb-2 mt-1">
                      Update your details, then send it for review again.
                    </p>`}
                ${!readOnly && ctx.state === "nominations"
                  ? html`<button
                      class="btn btn-gold btn-sm"
                      id="resubmitBtn"
                      type="button"
                    >
                      Send for review again
                    </button>`
                  : html`<span class="small"
                      >Nominations are closed, so this entry can’t be sent
                      again.</span
                    >`}
              </div>`
            : ""}
          ${isWinner
            ? html`<div class="oga-card mb-4 text-center">
                <h2 class="h4 mt-0">
                  <i class="fa-solid fa-trophy text-gold me-2"></i>
                  Congratulations, you won${cat ? ` ${cat.name}` : ""}!
                </h2>
                <p class="mb-0">The organisers have announced the winners.</p>
              </div>`
            : ""}

          <div class="oga-card mb-4">
            <h2 class="h5 mt-0">Your category</h2>
            <p>${cat?.name ?? "—"}</p>
            <ol class="list-unstyled d-flex flex-wrap gap-2 mb-2 small">
              ${steps.map(
                ([, label], i) =>
                  html`<li
                    class="oga-badge ${i === at ? "" : "opacity-50"}"
                    aria-current="${i === at ? "step" : "false"}"
                  >
                    ${i + 1}. ${label}
                  </li>`,
              )}
            </ol>
            <p class="small text-secondary mb-0">
              ${ctx.state === "voting"
                ? "Voting is open. Live counts and rankings are never shown to anyone, so everyone stays in suspense until the winners are announced."
                : ctx.state === "voting-closed"
                  ? "Voting has closed and the results are being verified. Winners are announced by the organisers."
                  : ctx.state === "results"
                    ? isWinner
                      ? "You are one of the announced winners."
                      : "The winners have been announced."
                    : me.status === "approved"
                      ? "You’re approved. Share your profile so people are ready when voting opens."
                      : "Complete your profile while your entry is reviewed."}
            </p>
          </div>

          ${percent < 100 && !readOnly
            ? html`<div class="oga-card mb-4">
                <h2 class="h5 mt-0">Profile ${percent}% complete</h2>
                <div class="progress mb-2" style="height:6px">
                  <div
                    class="progress-bar"
                    role="progressbar"
                    style="width:${percent}%"
                    aria-valuenow="${percent}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                  ></div>
                </div>
                <ul class="small mb-0">
                  ${checklist
                    .filter(([, ok]) => !ok)
                    .map(([label]) => html`<li>Add: ${label}</li>`)}
                </ul>
              </div>`
            : ""}
          ${!readOnly && me.occasionId === ctx.occasion.id
            ? html`
                <div id="updatesCard" class="mb-4"></div>
                <div id="passCard" class="mb-4"></div>
              `
            : ""}
          ${ctx.occasion.flier?.enabled && me.occasionId === ctx.occasion.id
            ? html`<div class="oga-card mb-4" id="flierCard">
                <h2 class="h5 mt-0">Your promo flier</h2>
                <p class="small text-secondary">
                  Download a ready-made flier with your photo, name and a QR
                  code to your profile. Share it on your status and stories.
                  ${me.photo ? "" : "Add a profile photo to make it stand out."}
                </p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                  <select
                    class="form-select form-select-sm w-auto"
                    id="flierSize"
                    aria-label="Flier size"
                  >
                    ${Object.entries(FLIER_SIZES).map(
                      ([key, v]) =>
                        html`<option value="${key}">${v.label}</option>`,
                    )}
                  </select>
                  <button
                    class="btn btn-gold btn-sm"
                    id="flierDownload"
                    type="button"
                  >
                    <i class="fa-solid fa-download me-1"></i>Download PNG
                  </button>
                </div>
                <canvas
                  id="flierCanvas"
                  data-category="${cat?.name ?? ""}"
                  class="w-100 rounded"
                  style="max-width:360px;height:auto"
                  aria-label="Preview of your promo flier"
                ></canvas>
              </div>`
            : ""}
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
                  <div class="d-flex flex-wrap gap-2 mt-3">
                    <a
                      class="btn btn-ghost btn-sm"
                      target="_blank"
                      rel="noopener"
                      href="https://wa.me/?text=${encodeURIComponent(
                        `${shareText} ${new URL(profileUrl, window.location.href).href}`,
                      )}"
                      ><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a
                    >
                    <a
                      class="btn btn-ghost btn-sm"
                      target="_blank"
                      rel="noopener"
                      href="https://twitter.com/intent/tweet?text=${encodeURIComponent(
                        shareText,
                      )}&url=${encodeURIComponent(
                        new URL(profileUrl, window.location.href).href,
                      )}"
                      ><i class="fa-brands fa-x-twitter me-1"></i>Post</a
                    >
                    <a
                      class="btn btn-ghost btn-sm"
                      target="_blank"
                      rel="noopener"
                      href="https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(
                        new URL(profileUrl, window.location.href).href,
                      )}"
                      ><i class="fa-brands fa-facebook me-1"></i>Facebook</a
                    >
                    <button
                      class="btn btn-ghost btn-sm"
                      id="shareBtn"
                      type="button"
                      hidden
                    >
                      <i class="fa-solid fa-share-nodes me-1"></i>Share…
                    </button>
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
              ${me.matricNo
                ? html`<div class="col-sm-6">
                    <small class="text-secondary d-block">Matric number</small>
                    <strong>${me.matricNo}</strong>
                  </div>`
                : ""}
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
                          <div class="min-w-0">
                            <h2
                              class="modal-title h5"
                              id="editProfileModalLabel"
                            >
                              Edit nominee profile
                            </h2>
                            <p class="small text-secondary mb-0">
                              Update your details for this edition.
                            </p>
                          </div>

                          <button
                            type="button"
                            class="btn-close btn-close-white ms-2"
                            data-bs-dismiss="modal"
                            aria-label="Close"
                          ></button>
                        </div>

                        <div class="modal-body">
                          <div class="row g-3">
                            <div class="col-12">
                              <div
                                class="d-flex flex-column align-items-center text-center gap-2"
                              >
                                <div id="photoPreview">
                                  <img
                                    src="${mediaUrl(me.photo)}"
                                    alt="Profile photo preview"
                                    class="rounded-circle"
                                    width="100"
                                    height="100"
                                    style="object-fit:cover;${me.photo
                                      ? ""
                                      : "display:none;"}"
                                  />
                                </div>

                                <div class="w-100">
                                  <label class="form-label" for="profilePhoto">
                                    Profile photo
                                  </label>
                                  <input
                                    class="form-control"
                                    type="file"
                                    id="profilePhoto"
                                    name="profilePhoto"
                                    accept="image/jpeg,image/png,image/webp"
                                  />
                                  <div class="form-text">
                                    JPG, PNG or WebP. Maximum size: 1 MB.
                                  </div>
                                </div>
                              </div>
                            </div>

                            <div class="col-12 col-md-6">
                              ${field({
                                label: "Full name",
                                name: "name",
                                required: true,
                                value: me.name,
                              })}
                            </div>

                            <div class="col-12 col-md-6">
                              ${field({
                                label: "Nickname (optional)",
                                name: "nickname",
                                value: me.nickname ?? "",
                                help: "Shown on your promo flier only. Up to 40 characters.",
                              })}
                            </div>

                            <div class="col-12 col-md-6">
                              ${field({
                                label: "Phone number",
                                name: "phone",
                                type: "tel",
                                required: true,
                                value: me.phone ?? "",
                              })}
                            </div>

                            <div class="col-12 col-md-6">
                              ${field({
                                label: "Department",
                                name: "department",
                                required: true,
                                value: me.department ?? "",
                              })}
                            </div>

                            <div class="col-12 col-md-6">
                              ${field({
                                label: "Level",
                                name: "level",
                                value: me.level ?? "",
                                help: "Your level is kept by the organisers. If it is wrong, contact them.",
                                attrs: "readonly",
                              })}
                            </div>

                            <div class="col-12">
                              ${field({
                                label: "About you",
                                name: "bio",
                                type: "textarea",
                                rows: 4,
                                value: me.bio ?? "",
                                help: "Maximum 500 characters.",
                              })}
                            </div>
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

/** An account made before matric numbers existed has to give one the next time it is opened. */
async function askForMatric() {
  const example = APP.matric?.formats?.find((f) => f.example)?.example ?? "";
  await notify.prompt({
    title: "Add your matric number",
    text: "Every student has one matric number on this platform. You only do this once.",
    placeholder: example,
    confirmText: "Save",
    dismissible: false,
    save: async (value) => {
      if (!normalizeMatric(value, APP.matric)) {
        throw new Error(
          `That is not a valid matric number${example ? ` (for example ${example})` : ""}.`,
        );
      }
      await saveMatric(value);
    },
  });
  location.reload();
}

export function init(root, ctx, { params }) {
  if (params.get("viewAs")) return;
  if (currentNominee()?.needsMatric) askForMatric();

  root.querySelector("#logoutBtn")?.addEventListener("click", async () => {
    if (
      await notify.confirm({
        title: "Log out?",
        confirmText: "Log out",
      })
    ) {
      logoutNominee();
      go("login");
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

  // ---- announcements for nominees ----
  const updatesBox = root.querySelector("#updatesCard");
  if (updatesBox) {
    getNomineeAnnouncements()
      .then((list) => {
        if (!list.length) return;
        updatesBox.innerHTML = toHTML(
          html`<div class="oga-card">
            <h2 class="h5 mt-0">
              <i class="fa-solid fa-bullhorn me-2"></i>Updates
            </h2>
            ${list.slice(0, 5).map(
              (a) =>
                html`<div class="mb-3">
                  <strong>${a.pinned ? "📌 " : ""}${a.title}</strong>
                  <time class="small text-secondary ms-2"
                    >${a.at ? new Date(a.at).toLocaleDateString() : ""}</time
                  >
                  <p class="mb-0 small" style="white-space:pre-line">
                    ${a.body}
                  </p>
                </div>`,
            )}
          </div>`,
        );
      })
      .catch(() => {});
  }

  // ---- Access Pass (free event entry) ----
  const passBox = root.querySelector("#passCard");
  const passView = (st) =>
    html`<div class="oga-card">
      <h2 class="h5 mt-0">
        <i class="fa-solid fa-ticket me-2"></i>Your Nominee Access Pass
      </h2>
      ${st.claimed
        ? html`<p class="small text-secondary">
              Show a QR code at the gate. Each code is scanned once. You are
              invited whether or not you win; winners are announced at the
              event.
            </p>
            <div class="row g-3">
              ${st.tickets.map(
                (t) =>
                  html`<div class="col-6 col-md-4">
                    <div
                      class="border rounded p-2 text-center bg-white text-dark"
                    >
                      <div
                        data-pass-qr="${t.code}"
                        style="max-width:150px;margin:0 auto"
                      ></div>
                      <div class="small fw-bold mt-1">${t.holder}</div>
                      <code class="small">${t.code}</code>
                      <div class="small">
                        ${t.void
                          ? "Cancelled"
                          : t.used
                            ? "Used"
                            : "Not used yet"}
                      </div>
                    </div>
                  </div>`,
              )}
            </div>
            <button
              class="btn btn-ghost btn-sm mt-3"
              id="passPrint"
              type="button"
            >
              <i class="fa-solid fa-print me-1"></i>Print
            </button>`
        : st.eligible
          ? html`<p class="small text-secondary">
                You are approved. Claim your free
                pass${st.guests
                  ? ` (it admits you and ${st.guests} guest${st.guests === 1 ? "" : "s"})`
                  : ""}.
              </p>
              <button class="btn btn-gold btn-sm" id="passClaim" type="button">
                Claim my Access Pass
              </button>`
          : html`<p class="small text-secondary mb-0">
              Your Access Pass opens once one of your entries is approved.
            </p>`}
    </div>`;
  const showPass = async () => {
    if (!passBox) return;
    let st;
    try {
      st = await getAccessPass();
    } catch {
      return;
    }
    if (!st.enabled && !st.claimed) {
      passBox.replaceChildren();
      return;
    }
    passBox.innerHTML = toHTML(passView(st));
    passBox
      .querySelector("#passPrint")
      ?.addEventListener("click", () => print());
    passBox
      .querySelector("#passClaim")
      ?.addEventListener("click", async (event) => {
        event.currentTarget.disabled = true;
        try {
          await claimAccessPass();
          notify.toast("success", "Your Access Pass is ready");
          await showPass();
        } catch (err) {
          event.currentTarget.disabled = false;
          await notify.warning("Couldn’t claim it", err.message);
        }
      });
    if (st.claimed) {
      try {
        const qrcode = await loadQr();
        passBox.querySelectorAll("[data-pass-qr]").forEach((el) => {
          const qr = qrcode(0, "M");
          qr.addData(el.dataset.passQr);
          qr.make();
          el.innerHTML = qr.createSvgTag({
            cellSize: 4,
            margin: 0,
            scalable: true,
          });
        });
      } catch {
        passBox
          .querySelectorAll("[data-pass-qr]")
          .forEach(
            (el) => (el.textContent = "Show the code below at the gate."),
          );
      }
    }
  };
  showPass();

  // ---- promo flier ----
  const flierCanvas = root.querySelector("#flierCanvas");
  if (flierCanvas) {
    const who = currentNominee();
    const draw = () =>
      drawFlier(flierCanvas, {
        size: root.querySelector("#flierSize").value,
        cfg: ctx.occasion.flier,
        name: who.name,
        nickname: who.nickname,
        category: flierCanvas.dataset.category,
        edition: `${ctx.occasion.name} ${ctx.occasion.edition}`,
        photo: who.photo,
        voteUrl: new URL(
          `${ROOT}nominee?id=${encodeURIComponent(who.id)}`,
          window.location.href,
        ).href,
      });
    draw();
    root.querySelector("#flierSize").addEventListener("change", draw);
    root.querySelector("#flierDownload").addEventListener("click", async () => {
      try {
        await draw();
        await downloadFlier(
          flierCanvas,
          `${who.name.replace(/[^a-z0-9]+/gi, "-").toLowerCase()}-flier.png`,
        );
      } catch (err) {
        await notify.warning("Couldn’t create the flier", err.message);
      }
    });
  }

  root
    .querySelector("#resubmitBtn")
    ?.addEventListener("click", async (event) => {
      event.currentTarget.disabled = true;
      try {
        await resubmitNomineeEntry(currentNominee().id);
        notify.toast("success", "Sent for review again");
        window.location.reload();
      } catch (err) {
        event.currentTarget.disabled = false;
        await notify.warning("Couldn’t send it", err.message);
      }
    });

  const shareBtn = root.querySelector("#shareBtn");
  if (shareBtn && navigator.share) {
    shareBtn.hidden = false;
    shareBtn.addEventListener("click", () => {
      const me = currentNominee();
      navigator
        .share({
          title: me.name,
          text: `Vote for me, ${me.name}!`,
          url: root.querySelector("#profileUrl").value,
        })
        .catch(() => {});
    });
  }

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
  let selectedFile = null;

  photoInput?.addEventListener("change", () => {
    selectedPhoto = null;
    selectedFile = null;

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
      selectedFile = file;
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
        nickname: [rules.max(40)],
      },

      async onSubmit(values) {
        try {
          await updateNominee({
            name: values.name.trim(),
            nickname: (values.nickname ?? "").trim(),
            phone: values.phone.trim(),
            department: values.department.trim(),
            level: values.level,
            bio: values.bio.trim(),
          });
          if (selectedFile) await uploadNomineePhoto(selectedFile);

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
    const mine = listMyEntries()
      .filter((entry) => entry.occasionId === ctx.occasion.id)
      .map((entry) => String(entry.categoryId));

    bindCategoryPicker(newEntryForm, {
      max: APP.voting.maxCategoriesPerNominee,
      taken: mine,
      level: currentNominee()?.accountLevel ?? currentNominee()?.level ?? "",
    });

    bindForm(newEntryForm, {
      schema: {},

      async onSubmit(values, formEl) {
        const categoryIds = pickedCategories(formEl);

        if (categoryIds.length === 0) {
          throw new Error("Choose at least one category.");
        }

        const r = await createNomineeEntry({
          occasionId: ctx.occasion.id,
          categoryIds,
        });

        if (r.paymentRequired) {
          location.assign(r.paymentLink);
          await new Promise(() => {});
        }

        await notify.success(
          "Entry created",
          "Your new entry is pending review. Your profile and photo are shared by all your categories.",
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
          "Your account is now marked as verified.",
        );

        location.reload();
      },
    });
  }
}
