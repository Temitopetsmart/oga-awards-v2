import { ROOT } from "../../../shared/js/config/app.config.js";
import { html, safeClass } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { hasFeature } from "../../../shared/js/services/event.service.js";
import { occasionCard } from "../../../shared/js/components/cards.js";

/** Hero: badge, headline, lead, buttons, and an `aside` slot (countdown, winner spotlight…). */
export function hero({ ctx, copy, aside }) {
  const ctas = (copy.ctas ?? []).filter(
    (c) => !c.feature || hasFeature(ctx.occasion, c.feature),
  );
  return html` <section class="oga-hero">
    <div class="oga-hero__glow" aria-hidden="true"></div>
    <div class="container position-relative">
      <div class="row align-items-center gy-5 gx-lg-5">
        <div class="col-lg-7">
          <span class="oga-badge mb-4"
            ><span class="oga-dot"></span>${ctx.stateInfo.label}</span
          >
          <p class="oga-eyebrow d-block mb-2">${copy.kicker}</p>
          <h1 class="oga-hero__title">
            ${copy.title} <span class="text-gold">${copy.highlight}</span>
          </h1>
          <p class="oga-hero__lead">${copy.lead}</p>
          <div class="d-flex flex-wrap gap-3 mt-4">
            ${ctas.map(
              (c) =>
                html`<a
                  class="btn btn-lg ${c.variant === "gold"
                    ? "btn-gold"
                    : "btn-ghost"}"
                  href="${ROOT}${c.href}"
                  >${c.label}</a
                >`,
            )}
          </div>
        </div>
        <div class="col-lg-5">${aside}</div>
      </div>
    </div>
  </section>`;
}

/** Countdown panel for the hero aside. Renders a decorative emblem if there is nothing to count to. */
export function countdownPanel(countdown) {
  if (!countdown)
    return html`<div class="oga-panel oga-emblem">
      <i class="fa-solid fa-trophy" aria-hidden="true"></i>
    </div>`;
  return html` <div class="oga-panel">
    <p class="oga-panel__label">${countdown.label}</p>
    <oga-countdown
      target="${countdown.at}"
      label="${countdown.label}"
    ></oga-countdown>
    <p class="oga-panel__note">
      ${formatDate(countdown.at, { dateStyle: "full", timeStyle: "short" })}
    </p>
  </div>`;
}

export const sectionHead = (eyebrow, title, lead) =>
  html` <div class="oga-section-head">
    ${eyebrow ? html`<span class="oga-eyebrow">${eyebrow}</span>` : ""}
    <h2>${title}</h2>
    ${lead ? html`<p>${lead}</p>` : ""}
  </div>`;

/** Icon + title + text tiles. */
export const featureGrid = ({
  title,
  lead,
  items = [],
  alt = false,
  eyebrow = "",
}) =>
  items.length
    ? html` <section class="oga-section ${alt ? "oga-section--alt" : ""}">
        <div class="container">
          ${sectionHead(eyebrow, title, lead)}
          <div class="row g-4 justify-content-center">
            ${items.map(
              (i) =>
                html` <div
                  class="col-md-6 ${items.length === 3
                    ? "col-lg-4"
                    : "col-lg-3"}"
                >
                  <div class="oga-card oga-card--hover">
                    <span class="oga-icon"
                      ><i class="fa-solid ${safeClass(i.icon)}"></i
                    ></span>
                    <h3>${i.title}</h3>
                    <p>${i.text}</p>
                  </div>
                </div>`,
            )}
          </div>
        </div>
      </section>`
    : "";

/** A grid of any cards. `cols` is the Bootstrap lg column width per item. */
export const cardGrid = ({
  title,
  lead,
  eyebrow = "",
  cards = [],
  cols = 4,
  alt = false,
  emptyText = "Nothing to show yet.",
}) =>
  html` <section class="oga-section ${alt ? "oga-section--alt" : ""}">
    <div class="container">
      ${sectionHead(eyebrow, title, lead)}
      ${cards.length
        ? html`<div class="row g-4">
            ${cards.map(
              (c) => html`<div class="col-sm-6 col-lg-${cols}">${c}</div>`,
            )}
          </div>`
        : html`<div class="oga-empty">
            <i class="fa-regular fa-folder-open"></i>
            <p class="mb-0">${emptyText}</p>
          </div>`}
    </div>
  </section>`;

/** "More from OGA" — special occasions (e.g. Miss OGITECH) that are switched on. Hidden when none. */
export const occasionStrip = (specials) =>
  specials.length
    ? html` <section class="oga-section oga-section--alt">
        <div class="container">
          ${sectionHead(
            "More from OGA",
            "Other occasions",
            "Special events happening alongside the awards.",
          )}
          <div class="row g-4 justify-content-center">
            ${specials.map(
              (o) =>
                html`<div class="col-md-6 col-lg-4">${occasionCard(o)}</div>`,
            )}
          </div>
        </div>
      </section>`
    : "";
