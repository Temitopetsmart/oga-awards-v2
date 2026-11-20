import { html } from "../../../shared/js/core/dom.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  updateNominee,
  uploadNomineePhoto,
  switchNomineeEntry,
  useMainDetails,
} from "../../../shared/js/services/auth.service.js";
import { href, card } from "../ui.js";

export const title = "My profile";

const kv = (label, value) =>
  html`<div class="col-sm-6 pt-kv">
    <small>${label}</small><strong>${value || "Not provided"}</strong>
  </div>`;

export default function profile(p) {
  const { me, readOnly, percent, checklist, mineHere = [], catName } = p;
  // A nominee in several categories keeps one set of personal details, but the photo and bio can differ per category.
  const several = !readOnly && mineHere.length > 1;
  const editing = several
    ? html`<div class="border rounded-3 p-3 mb-4">
        <label class="form-label" for="editingEntry"
          >Which category are you editing?</label
        >
        <select class="form-select" id="editingEntry">
          ${mineHere.map(
            (e) =>
              html`<option value="${e.id}" ${e.id === me.id ? "selected" : ""}>
                ${catName(e) ?? "Category"}${e.isMain ? " (main)" : ""}
              </option>`,
          )}
        </select>
        <p class="small text-secondary mb-0 mt-2">
          ${me.isMain
            ? "This is your main entry. The photo and bio you set here are used by every category that has not got its own."
            : html`The photo and bio below are for
                <strong>${catName(me)}</strong> only. Your name, phone and
                department are the same everywhere.`}
        </p>
        ${!me.isMain && (me.photoCustom || me.bioCustom)
          ? html`<div class="d-flex flex-wrap gap-2 mt-2">
              ${me.photoCustom
                ? html`<button
                    class="btn btn-ghost btn-sm"
                    type="button"
                    data-use-main="photo"
                  >
                    Use my main photo
                  </button>`
                : ""}
              ${me.bioCustom
                ? html`<button
                    class="btn btn-ghost btn-sm"
                    type="button"
                    data-use-main="bio"
                  >
                    Use my main bio
                  </button>`
                : ""}
            </div>`
          : ""}
      </div>`
    : "";
  const checks = html`<ul class="list-unstyled mb-0 small">
    ${checklist.map(
      ([label, ok]) =>
        html`<li class="mb-1">
          <i
            class="fa-solid ${ok
              ? "fa-circle-check text-success"
              : "fa-circle text-secondary"} me-2"
          ></i
          >${label}
        </li>`,
    )}
  </ul>`;

  return html`<div class="row g-4">
    <div class="col-lg-8">
      ${readOnly
        ? card(
            "Personal information",
            html`<div class="row g-3">
              ${kv("Full name", me.name)}${kv("Email", me.email)}${kv(
                "Phone",
                me.phone,
              )}${kv("Department", me.department)}${kv("Level", me.level)}${kv(
                "Matric number",
                me.matricNo,
              )}
            </div>`,
            { icon: "fa-user" },
          )
        : card(
            "Your details",
            html`<form id="profileForm" novalidate>
              ${editing}
              <div class="d-flex align-items-center gap-3 mb-3">
                <div id="photoPreview">
                  ${me.photo
                    ? html`<img
                        class="pt-photo"
                        src="${mediaUrl(me.photo)}"
                        alt="Your profile photo"
                      />`
                    : html`<span
                        class="pt-photo d-grid"
                        style="place-items:center;background:var(--oga-surface-2)"
                        ><i class="fa-solid fa-user fa-2x text-secondary"></i
                      ></span>`}
                </div>
                <div class="flex-grow-1">
                  <label class="form-label" for="profilePhoto"
                    >Profile photo</label
                  >
                  <input
                    class="form-control"
                    type="file"
                    id="profilePhoto"
                    accept="image/jpeg,image/png,image/webp"
                  />
                  <div class="form-text">
                    JPG, PNG or WebP, up to 1 MB. A clear face photo works best
                    on the flier.
                  </div>
                </div>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  ${field({
                    label: "Full name",
                    name: "name",
                    required: true,
                    value: me.name,
                  })}
                </div>
                <div class="col-md-6">
                  ${field({
                    label: "Nickname (optional)",
                    name: "nickname",
                    value: me.nickname ?? "",
                    help: "Shown on your promo flier only. Up to 40 characters.",
                  })}
                </div>
                <div class="col-md-6">
                  ${field({
                    label: "Phone number",
                    name: "phone",
                    type: "tel",
                    required: true,
                    value: me.phone ?? "",
                  })}
                </div>
                <div class="col-md-6">
                  ${field({
                    label: "Department",
                    name: "department",
                    required: true,
                    value: me.department ?? "",
                  })}
                </div>
                <div class="col-md-6">
                  ${field({
                    label: "Level",
                    name: "level",
                    value: me.level ?? "",
                    help: "Your level is kept by the organisers. If it is wrong, contact them.",
                    attrs: "readonly",
                  })}
                </div>
                ${me.matricNo
                  ? html`<div class="col-md-6">
                      ${field({
                        label: "Matric number",
                        name: "matric",
                        value: me.matricNo,
                        attrs: "readonly",
                      })}
                    </div>`
                  : ""}
                <div class="col-12">
                  ${field({
                    label: "About you",
                    name: "bio",
                    type: "textarea",
                    rows: 4,
                    value: me.bio ?? "",
                    help:
                      several && !me.isMain
                        ? "Maximum 500 characters. Shown on your public profile for this category."
                        : "Maximum 500 characters. Shown on your public profile.",
                  })}
                </div>
              </div>
              <button class="btn btn-gold mt-2" type="submit">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save changes
              </button>
            </form>`,
            { icon: "fa-user-pen" },
          )}
    </div>
    <div class="col-lg-4 d-grid gap-4 align-content-start">
      ${card(
        `Profile ${percent}% complete`,
        html`<div class="progress mb-3" style="height:6px">
            <div
              class="progress-bar"
              role="progressbar"
              style="width:${percent}%"
              aria-valuenow="${percent}"
              aria-valuemin="0"
              aria-valuemax="100"
            ></div>
          </div>
          ${checks}`,
        { icon: "fa-list-check" },
      )}
      ${me.status === "approved"
        ? card(
            "Your public page",
            html`<p class="small text-secondary">This is what voters see.</p>
              <a
                class="btn btn-ghost btn-sm"
                target="_blank"
                rel="noopener"
                href="${p.profileUrl}"
                ><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open
                my public profile</a
              >`,
            { icon: "fa-eye" },
          )
        : ""}
    </div>
  </div>`;
}

export function init(root, p) {
  const form = root.querySelector("#profileForm");
  if (!form) return;
  root.querySelector("#editingEntry")?.addEventListener("change", (e) => {
    try {
      switchNomineeEntry(e.target.value);
      location.reload();
    } catch (err) {
      notify.failure(err);
    }
  });
  root.querySelectorAll("[data-use-main]").forEach((b) =>
    b.addEventListener("click", async () => {
      b.disabled = true;
      try {
        await useMainDetails(p.me.id, { [b.dataset.useMain]: true });
        notify.toast("success", "Back on your main " + b.dataset.useMain);
        location.reload();
      } catch (err) {
        b.disabled = false;
        notify.failure(err);
      }
    }),
  );
  const input = root.querySelector("#profilePhoto");
  const preview = root.querySelector("#photoPreview");
  let file = null;
  input.addEventListener("change", () => {
    file = null;
    const f = input.files?.[0];
    if (!f) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(f.type)) {
      input.value = "";
      return notify.failure(new Error("Choose a JPG, PNG or WebP image."));
    }
    if (f.size > 1024 * 1024) {
      input.value = "";
      return notify.failure(new Error("Your photo must not exceed 1 MB."));
    }
    const reader = new FileReader();
    reader.onload = () => {
      file = f;
      const img = Object.assign(document.createElement("img"), {
        src: String(reader.result),
        alt: "New profile photo preview",
        className: "pt-photo",
      });
      preview.replaceChildren(img);
    };
    reader.onerror = () => {
      input.value = "";
      notify.failure(new Error("The selected image could not be read."));
    };
    reader.readAsDataURL(f);
  });
  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      phone: [rules.required(), rules.phone()],
      department: [rules.required()],
      level: [rules.required()],
      bio: [rules.max(500)],
      nickname: [rules.max(40)],
    },
    async onSubmit(v) {
      try {
        await updateNominee({
          name: v.name.trim(),
          nickname: (v.nickname ?? "").trim(),
          phone: v.phone.trim(),
          department: v.department.trim(),
          level: v.level,
          bio: v.bio.trim(),
        });
        if (file) await uploadNomineePhoto(file);
        await notify.success("Profile saved", "Your profile has been updated.");
        location.reload();
      } catch (err) {
        notify.failure(err);
      }
    },
  });
}
