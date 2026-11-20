import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { avatar } from "../../../shared/js/components/cards.js";
import {
  getCategories,
  getNominees,
} from "../../../shared/js/services/catalog.service.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

export const title = "Nominee";

export default async function nominee(ctx, { params }) {
  const [nominees, cats] = await Promise.all([
    getNominees(ctx.occasion.id),
    getCategories(ctx.occasion.id),
  ]);

  const n = nominees.find((x) => x.id === params.get("id"));

  if (!n)
    return notice({
      icon: "fa-user-slash",
      title: "Nominee not found",
      text: "This profile does not exist or is not public yet.",
      actions: [{ label: "Browse nominees", href: "categories" }],
    });

  const cat = cats.find((c) => c.id === n.categoryId);
  const voting = ctx.state === "voting";
  const firstName = n.name.split(" ")[0];

  const facts = [
    { label: "Category", value: cat?.name },
    { label: "Department", value: n.department },
    { label: "Level", value: n.level },
  ].filter((item) => item.value);

  document.title = `${n.name} — ${ctx.occasion.name} ${ctx.occasion.edition}`;

  return html`
    ${pageHero({
      eyebrow: "Official nominee profile",
      title: n.name,
      lead: [n.department, n.level].filter(Boolean).join(" · "),
      crumbs: [{ label: "Categories", href: "categories" }, { label: n.name }],
    })}
    ${pageBody(
      html`
        <article class="oga-nominee-profile">
          <div class="oga-nominee-profile__visual">
            <div class="oga-nominee-profile__portrait">${avatar(n, true)}</div>

            <div class="oga-nominee-profile__identity">
              <span class="oga-eyebrow">OGA ${ctx.occasion.edition}</span>
              <h2>${n.name}</h2>
              <p>
                ${[n.department, n.level].filter(Boolean).join(" · ") ||
                "Official OGA nominee"}
              </p>
              ${cat
                ? html`<span class="oga-badge">
                    <i class="fa-solid fa-award"></i> ${cat.name}
                  </span>`
                : ""}
            </div>
          </div>

          <div class="oga-nominee-profile__content">
            <section class="oga-profile-block">
              <span class="oga-eyebrow">The nominee</span>
              <h2>Get to know ${firstName}</h2>
              <p class="oga-profile-bio">
                ${n.bio ||
                "This nominee's story and achievements will be updated soon."}
              </p>
            </section>

            ${facts.length
              ? html`<section class="oga-profile-block">
                  <h3>Profile information</h3>
                  <div class="oga-profile-facts">
                    ${facts.map(
                      (fact) =>
                        html`<div class="oga-profile-fact">
                          <span>${fact.label}</span>
                          <strong>${fact.value}</strong>
                        </div>`,
                    )}
                  </div>
                </section>`
              : ""}

            <section class="oga-profile-actions">
              <div>
                <h3>Show your support</h3>
                <p>
                  Celebrate excellence by supporting this nominee in the
                  ${ctx.occasion.name}.
                </p>
              </div>

              <div class="d-flex flex-wrap gap-2">
                ${voting
                  ? html`<a
                      class="btn btn-gold"
                      href="${ROOT}vote?nominee=${encodeURIComponent(n.id)}"
                    >
                      <i class="fa-solid fa-check-to-slot me-2"></i>
                      Vote for ${firstName}
                    </a>`
                  : ""}
                <button class="btn btn-ghost" id="shareBtn">
                  <i class="fa-solid fa-share-nodes me-2"></i>
                  Share profile
                </button>
              </div>
            </section>
          </div>
        </article>
      `,
      { width: "68rem" },
    )}
  `;
}

export function init(root) {
  root.querySelector("#shareBtn")?.addEventListener("click", async () => {
    const url = location.href.split("#")[0].replace(/[?&]preview=[^&]*/, "");

    try {
      if (navigator.share)
        await navigator.share({ title: document.title, url });
      else {
        await navigator.clipboard.writeText(url);
        notify.toast("success", "Link copied");
      }
    } catch {
      /* User cancelled sharing. */
    }
  });
}
