import { html, safeClass } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { hasFeature } from "../../../shared/js/services/event.service.js";
import { submitCommitteeApplication } from "../../../shared/js/services/forms.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";
import { sectionHead } from "../sections/sections.js";

export const title = "Committee";
const LEVELS = ["ND I", "ND II", "HND I", "HND II"].map((l) => ({
  value: l,
  label: l,
}));

export default async function committee(ctx) {
  const data = await getResource("committee");
  const open = hasFeature(ctx.occasion, "committeeRecruitment");
  return html` ${pageHero({
      eyebrow: "The team",
      title: "Meet the committee",
      lead: data.intro,
      crumbs: [{ label: "Committee" }],
    })}
    ${pageBody(
      html` ${data.units.map((u) => {
        const members = data.members.filter((m) => m.unitId === u.id);
        return members.length
          ? html` <section class="mb-5">
              <div class="oga-cat__head">
                <span class="oga-icon"
                  ><i class="fa-solid ${safeClass(u.icon)}"></i
                ></span>
                <div>
                  <h2 class="h3 mb-0">${u.name}</h2>
                  <p class="mb-0">${u.description}</p>
                </div>
              </div>
              <div class="row g-4">
                ${members.map(
                  (m) =>
                    html` <div class="col-6 col-md-4 col-lg-3">
                      <article class="oga-card oga-member">
                        ${avatar(m)}
                        <h3>${m.name}</h3>
                        <p>${m.role}</p>
                      </article>
                    </div>`,
                )}
              </div>
            </section>`
          : "";
      })}`,
    )}
    <section class="oga-section oga-section--alt" id="apply">
      <div class="container" style="max-width:48rem">
        ${sectionHead(
          "Join us",
          "Join the committee",
          open
            ? "Want to help deliver the next edition? Tell us where you’d fit best."
            : "Recruitment is closed right now. Check back soon.",
        )}
        ${open
          ? html` <form id="applyForm" class="oga-formcard" novalidate>
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
                  options: data.units.map((u) => ({
                    value: u.id,
                    label: u.name,
                  })),
                  required: true,
                  col: "col-md-6",
                })}
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
                <i class="fa-solid fa-paper-plane me-2"></i>Submit application
              </button>
            </form>`
          : ""}
      </div>
    </section>`;
}

export function init(root) {
  const form = root.querySelector("#applyForm");
  if (!form) return;
  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      phone: [rules.required(), rules.phone()],
      department: [rules.required()],
      level: [rules.required("Choose your level.")],
      unitId: [rules.required("Choose a unit.")],
      motivation: [rules.required(), rules.min(30)],
    },
    async onSubmit(values) {
      await submitCommitteeApplication(values);
      form.reset();
      await notify.success(
        "Application received",
        "Thank you! We’ll be in touch by email.",
      );
    },
  });
}
