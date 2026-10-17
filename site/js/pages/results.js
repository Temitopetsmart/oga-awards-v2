import { html } from "../../../shared/js/core/dom.js";
import { getWinners } from "../../../shared/js/services/catalog.service.js";
import { winnerCard } from "../../../shared/js/components/cards.js";
import { countdownPanel, cardGrid } from "../sections/sections.js";
import { pageHero, notice } from "../sections/page-sections.js";

export const title = "Winners";

export default async function results(ctx) {
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
      actions: [{ label: "Back home", href: "index.html" }],
    })}`;
  }
  const winners = await getWinners(ctx.occasion.id);
  return html`${hero}${cardGrid({
    title: "Winners by category",
    cards: winners.map(winnerCard),
    cols: 4,
    emptyText: "Winners will be listed here shortly.",
  })}`;
}
