import { html } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { pageHero, notice } from "../sections/page-sections.js";
import { faceCard } from "../sections/extras.js";

export const title = "Face of the period";

export default async function spotlight() {
  const data = await getResource("spotlight").catch(() => ({ featured: null, history: [] }));
  const hero = pageHero({
    eyebrow: "In the spotlight",
    title: "Face of the period",
    lead: "The nominees we are putting in the spotlight.",
    crumbs: [{ label: "Face of the period" }],
  });
  if (!data.featured && !data.history?.length) {
    return html`${hero}${notice({
      icon: "fa-star",
      title: "No face chosen yet",
      text: "Check back soon.",
      actions: [{ label: "Back home", href: "./" }],
    })}`;
  }
  return html`${hero}<section class="container pb-5">
    ${data.featured
      ? html`<div class="row justify-content-center mb-5">
          <div class="col-lg-6">${faceCard(data.featured, { big: true })}</div>
        </div>`
      : ""}
    ${data.history?.length
      ? html`<h2 class="h4 mb-3">Earlier faces</h2>
          <div class="row g-4">
            ${data.history.map((f) => html`<div class="col-sm-6 col-lg-4">${faceCard(f)}</div>`)}
          </div>`
      : ""}
  </section>`;
}