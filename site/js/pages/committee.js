import { html, safeClass } from "../../../shared/js/core/dom.js";
import { getCommittee } from "../../../shared/js/services/catalog.service.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { hasFeature } from "../../../shared/js/services/event.service.js";
import {
  getApplicationWindow,
  listCommitteeApplications,
  submitRecruitmentApplication,
} from "../../../shared/js/services/committee-recruitment.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";
import { sectionHead } from "../sections/sections.js";

export const title = "Committee";

const LEVELS = ["ND I", "ND II", "HND I", "HND II"].map((level) => ({
  value: level,
  label: level,
}));

function readPhoto(file, config) {
  return new Promise((resolve, reject) => {
    if (!file || !file.size) {
      resolve("");
      return;
    }

    if (!config.photoUploadsEnabled) {
      reject(new Error("Photo uploads are currently disabled."));
      return;
    }

    if (!config.allowedPhotoTypes.includes(file.type)) {
      reject(new Error("Upload a JPG, PNG or WebP image."));
      return;
    }

    if (file.size > Number(config.photoMaxSizeKB) * 1024) {
      reject(
        new Error(`Your photo must not exceed ${config.photoMaxSizeKB} KB.`),
      );
      return;
    }

    const reader = new FileReader();

    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error("Could not read the photo."));
    reader.readAsDataURL(file);
  });
}

export default async function committee(ctx) {
  const data = await getCommittee(ctx.occasion.id);
  const window = getApplicationWindow(ctx.occasion.id);
  const featureEnabled = hasFeature(ctx.occasion, "committeeRecruitment");
  const open = featureEnabled && window.open;
  const applications = listCommitteeApplications(ctx.occasion.id);
  const existingApplicationCount = applications.length;
  const config = window.config;

  return html`
    ${pageHero({
      eyebrow: "The team",
      title: "Meet the committee",
      lead: data.intro,
      crumbs: [{ label: "Committee" }],
    })}
    ${pageBody(html`
      ${data.units.map((unit) => {
        const members = data.members.filter(
          (member) => member.unitId === unit.id,
        );

        return members.length
          ? html`
              <section class="mb-5">
                <div class="oga-cat__head">
                  <span class="oga-icon">
                    <i class="fa-solid ${safeClass(unit.icon)}"></i>
                  </span>
                  <div>
                    <h2 class="h3 mb-0">${unit.name}</h2>
                    <p class="mb-0">${unit.description}</p>
                  </div>
                </div>

                <div class="row g-4">
                  ${members.map(
                    (member) => html`
                      <div class="col-6 col-md-4 col-lg-3">
                        <article class="oga-card oga-member">
                          ${avatar(member)}
                          <h3>${member.name}</h3>
                          <p>${member.role}</p>
                        </article>
                      </div>
                    `,
                  )}
                </div>
              </section>
            `
          : "";
      })}
    `)}

    <section class="oga-section oga-section--alt" id="apply">
      <div class="container" style="max-width:48rem">
        ${sectionHead(
          "Recruitment",
          "Join the committee",
          open
            ? "Complete your application before the recruitment deadline. Shortlisted applicants will receive screening details."
            : window.reason || "Committee recruitment is currently closed.",
        )}
        ${open
          ? html`
              <div class="oga-formcard mb-4">
                <div class="d-flex justify-content-between gap-3 flex-wrap">
                  <div>
                    <strong>Application deadline</strong>
                    <p class="mb-0">
                      ${new Date(config.closesAt).toLocaleString()}
                    </p>
                  </div>
                  <div>
                    <strong>Applications received</strong>
                    <p class="mb-0">
                      ${existingApplicationCount} of ${config.targetApplicants}
                      expected
                    </p>
                  </div>
                </div>
              </div>

              <form id="applyForm" class="oga-formcard" novalidate>
                <div class="row">
                  ${field({
                    label: "Full name",
                    name: "name",
                    required: true,
                    autocomplete: "name",
                    col: "col-md-6",
                  })}
                  ${field({
                    label: "Email",
                    name: "email",
                    type: "email",
                    required: true,
                    autocomplete: "email",
                    col: "col-md-6",
                  })}
                  ${field({
                    label: "Phone",
                    name: "phone",
                    type: "tel",
                    required: true,
                    autocomplete: "tel",
                    col: "col-md-6",
                  })}
                  ${field({
                    label: "Department",
                    name: "department",
                    required: true,
                    col: "col-md-6",
                  })}
                  ${field({
                    label: "Level",
                    name: "level",
                    options: LEVELS,
                    required: true,
                    col: "col-md-6",
                  })}
                  ${field({
                    label: "Preferred unit",
                    name: "unitId",
                    options: data.units.map((unit) => ({
                      value: unit.id,
                      label: unit.name,
                    })),
                    required: true,
                    col: "col-md-6",
                  })}
                </div>

                <div class="oga-field mb-3">
                  <label class="form-label" for="applicantPhoto">
                    Applicant photograph
                  </label>
                  ${config.photoUploadsEnabled
                    ? html`
                        <input
                          class="form-control"
                          id="applicantPhoto"
                          name="applicantPhoto"
                          type="file"
                          accept="image/jpeg,image/png,image/webp"
                        />
                        <div class="form-text">
                          JPG, PNG or WebP. Maximum ${config.photoMaxSizeKB} KB.
                        </div>
                      `
                    : html`
                        <p class="form-text">
                          Photo uploads are disabled for this recruitment.
                        </p>
                      `}
                  <img
                    id="photoPreview"
                    alt="Applicant photo preview"
                    class="mt-3 rounded d-none"
                    style="width:110px;height:110px;object-fit:cover"
                  />
                </div>

                ${field({
                  label: "Why do you want to join?",
                  name: "motivation",
                  type: "textarea",
                  rows: 5,
                  required: true,
                  help: "At least 30 characters.",
                })}

                <button class="btn btn-gold btn-lg" type="submit">
                  <i class="fa-solid fa-paper-plane me-2"></i>
                  Submit application
                </button>
              </form>
            `
          : html`
              <div class="oga-formcard">
                <p class="mb-0">
                  ${window.reason ||
                  "Applications are closed for this edition."}
                </p>
              </div>
            `}
      </div>
    </section>
  `;
}

export function init(root, ctx) {
  const form = root.querySelector("#applyForm");
  if (!form) return;

  const config = getApplicationWindow(ctx.occasion.id).config;
  const photoInput = form.querySelector("#applicantPhoto");
  const preview = form.querySelector("#photoPreview");

  photoInput?.addEventListener("change", () => {
    const file = photoInput.files?.[0];

    if (!file) {
      preview?.classList.add("d-none");
      return;
    }

    if (
      !config.allowedPhotoTypes.includes(file.type) ||
      file.size > Number(config.photoMaxSizeKB) * 1024
    ) {
      photoInput.value = "";
      preview?.classList.add("d-none");
      notify.failure(
        new Error(
          `Choose a JPG, PNG or WebP image no larger than ${config.photoMaxSizeKB} KB.`,
        ),
      );
      return;
    }

    preview.src = URL.createObjectURL(file);
    preview.classList.remove("d-none");
  });

  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2), rules.max(100)],
      email: [rules.required(), rules.email()],
      phone: [rules.required(), rules.phone()],
      department: [rules.required(), rules.max(100)],
      level: [rules.required("Choose your level.")],
      unitId: [rules.required("Choose a unit.")],
      motivation: [rules.required(), rules.min(30), rules.max(3000)],
    },

    async onSubmit(values) {
      const latestWindow = getApplicationWindow(ctx.occasion.id);

      if (!latestWindow.open) {
        throw new Error(
          latestWindow.reason || "Applications have closed. Refresh the page.",
        );
      }

      const file = photoInput?.files?.[0];
      const photo = await readPhoto(file, latestWindow.config);

      submitRecruitmentApplication({
        ...values,
        photo: photo || null,
        editionId: ctx.occasion.id,
      });

      form.reset();
      preview?.classList.add("d-none");

      await notify.success(
        "Application submitted",
        "Your application has been recorded. An application confirmation is queued in this demo; no real email has been sent yet.",
      );
    },
  });
}
