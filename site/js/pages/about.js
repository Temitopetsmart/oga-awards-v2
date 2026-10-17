import { html } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { featureGrid } from "../sections/sections.js";
import { pageHero, pageBody, timeline } from "../sections/page-sections.js";

export const title = "About";

export default async function about(ctx) {
  const a = await getResource("about");
  return html` ${pageHero({
    eyebrow: a.kicker,
    title: a.title,
    lead: a.lead,
    crumbs: [{ label: "About" }],
  })}
  ${pageBody(
    html` <div class="row gy-5 gx-lg-5 align-items-start">
      <div class="col-lg-6">
        ${a.story.map((p) => html`<p class="fs-5">${p}</p>`)}
      </div>
      <div class="col-lg-5 offset-lg-1">
        <h2 class="h4 mb-4">
          ${ctx.occasion.name} ${ctx.occasion.edition} key dates
        </h2>
        ${timeline(ctx.occasion)}
      </div>
    </div>`,
  )}
  ${featureGrid({
    title: a.valuesTitle,
    items: a.values,
    alt: true,
    eyebrow: "Our values",
  })}`;
}
