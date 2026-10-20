import { ROOT } from "../../../shared/js/config/app.config.js";
import { html, safeClass } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import {
  pageHero,
  pageBody,
  notice,
  timeline,
} from "../sections/page-sections.js";

export const title = "Occasion";

/** Generic page for any special occasion (e.g. Miss OGITECH) defined in data/occasions.json. */
export default async function occasion(ctx, { params }) {
  const { editions } = await getResource("editions");
  const o = editions
    .flatMap((e) => e.occasions ?? [])
    .find((x) => x.id === params.get("id"));
  if (!o || !o.enabled)
    return notice({
      icon: "fa-calendar-xmark",
      title: "This occasion isn’t available",
      text: "It may not be open yet.",
      actions: [{ label: "Back home", href: "index.html" }],
    });
  document.title = `${o.name} — ${ctx.occasion.name}`;
  const hasDates = Object.keys(o.timeline ?? {}).length > 0;

  return html` ${pageHero({
    eyebrow: "Special occasion",
    title: o.name,
    lead: o.tagline,
    crumbs: [{ label: o.name }],
  })}
  ${pageBody(
    html` <div class="row gy-5 gx-lg-5">
      <div class="col-lg-7">
        <span class="oga-icon mb-3"
          ><i class="fa-solid ${safeClass(o.icon || "fa-star")}"></i
        ></span>
        <p class="fs-5">${o.description || ""}</p>
        <div class="d-flex flex-wrap gap-3 mt-4">
          ${(o.actions ?? []).map(
            (a) =>
              html`<a
                class="btn btn-lg ${a.variant === "ghost"
                  ? "btn-ghost"
                  : "btn-gold"}"
                href="${/^https?:/.test(a.href) ? a.href : ROOT + a.href}"
                >${a.label}</a
              >`,
          )}
        </div>
      </div>
      ${hasDates
        ? html`<div class="col-lg-5">
            <h2 class="h4 mb-4">Key dates</h2>
            ${timeline(o)}
          </div>`
        : ""}
    </div>`,
  )}`;
}
