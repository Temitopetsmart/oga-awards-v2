import { html } from "../../../shared/js/core/dom.js";
import { getWinners } from "../../../shared/js/services/catalog.service.js";
import { winnerCard } from "../../../shared/js/components/cards.js";
import { countdownPanel, cardGrid } from "../sections/sections.js";
import { pageHero, notice } from "../sections/page-sections.js";
import { getResource } from "../../../shared/js/core/http.js";
import { mediaUrl } from "../../../shared/js/core/media.js";

/** Winners of earlier editions, newest first: photo, name, category, a short intro. Only what has been published. */
const hallOfFame = (editions) =>
  editions.length
    ? html`<section class="container pb-5">
        <h2 class="h3 mb-4">Hall of fame</h2>
        ${editions.map(
          (e) =>
            html`<div class="mb-5">
              <h3 class="h5 text-gold">${e.name} ${e.edition ?? ""}</h3>
              <div class="row g-3">
                ${e.winners.map(
                  (w) =>
                    html`<div class="col-sm-6 col-lg-3">
                      <article class="oga-card h-100 text-center">
                        ${w.thumb || w.photo
                          ? html`<img
                              src="${mediaUrl(w.thumb || w.photo)}"
                              alt="${w.name}"
                              class="rounded-circle mb-2"
                              width="88"
                              height="88"
                              style="object-fit:cover"
                              loading="lazy"
                            />`
                          : ""}
                        <p class="small text-gold mb-1">${w.category}</p>
                        <h4 class="h6 mt-0 mb-1">${w.name}</h4>
                        ${w.department
                          ? html`<p class="small text-secondary mb-1">
                              ${w.department}
                            </p>`
                          : ""}
                        ${w.intro
                          ? html`<p class="small mb-0">${w.intro}</p>`
                          : ""}
                      </article>
                    </div>`,
                )}
              </div>
            </div>`,
        )}
      </section>`
    : "";

export const title = "Winners";

export default async function results(ctx) {
  const past = (await getResource("showcase").catch(() => [])).filter(
    (e) => e.occasionId !== ctx.occasion.id,
  );

  const hero = pageHero({
    eyebrow: "The results",
    title: "Winners",
    lead: `The students who took home the gold at ${ctx.occasion.name} ${ctx.occasion.edition}.`,
    crumbs: [{ label: "Winners" }],
  });

  if (ctx.state !== "results") {
    const waiting = ctx.state === "voting-closed";

    return html`${hero}${notice({
      icon: waiting ? "fa-clipboard-check" : "fa-hourglass-half",
      title: waiting ? "Winners coming soon" : "Results aren’t out yet",
      text: waiting
        ? "Voting has closed and results are being verified."
        : "Winners are announced after voting closes.",
      extra:
        waiting && ctx.countdown
          ? html`<div class="mt-3">${countdownPanel(ctx.countdown)}</div>`
          : "",
      actions: [{ label: "Back home", href: "./" }],
    })}${hallOfFame(past)}`;
  }

  const winners = await getWinners(ctx.occasion.id);

  return html`${hero}${cardGrid({
    title: "Winners by category",
    cards: winners.map(winnerCard),
    cols: 4,
    emptyText: "Winners will be listed here shortly.",
  })}${hallOfFame(past)}`;
}
