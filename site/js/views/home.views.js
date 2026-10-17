import { APP, ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { money, tpl } from "../../../shared/js/core/format.js";
import {
  categoryCard,
  nomineeCard,
  winnerCard,
  avatar,
} from "../../../shared/js/components/cards.js";
import {
  hero,
  countdownPanel,
  featureGrid,
  cardGrid,
  occasionStrip,
} from "../sections/sections.js";

/**
 * One view per home state. Each receives (ctx, data) and returns HTML.
 * To add a state: add it to STATES in app.config.js, copy in data/content.json,
 * data loading in site/js/pages/home.js, and a view here.
 */

const vars = () => ({
  price: money(APP.voting.pricePerVote),
  maxVotes: APP.voting.maxVotesPerPayment,
});
const items = (copy) =>
  (copy.highlights ?? []).map((h) => ({ ...h, text: tpl(h.text, vars()) }));
const features = (copy, extra = {}) =>
  featureGrid({
    title: copy.sectionTitle,
    lead: copy.sectionLead,
    items: items(copy),
    ...extra,
  });

/* Default: the normal OGA landing page */
const landing = (ctx) =>
  html` ${hero({ ctx, copy: ctx.copy, aside: countdownPanel(ctx.countdown) })}
  ${features(ctx.copy, { eyebrow: "The experience" })}
  ${occasionStrip(ctx.specials)}`;

/* Nominations are open */
const nominations = (ctx, { categories }) =>
  html` ${hero({ ctx, copy: ctx.copy, aside: countdownPanel(ctx.countdown) })}
  ${features(ctx.copy, { eyebrow: "Get started" })}
  ${cardGrid({
    title: ctx.copy.categoriesTitle,
    lead: ctx.copy.categoriesLead,
    alt: true,
    eyebrow: "Categories",
    cards: categories.map((c) =>
      categoryCard(c, `${ROOT}categories.html#${c.id}`),
    ),
    emptyText: "Categories will be announced soon.",
  })}
  ${occasionStrip(ctx.specials)}`;

/* Voting is live */
const voting = (ctx, { categories, nominees }) =>
  html` ${hero({ ctx, copy: ctx.copy, aside: countdownPanel(ctx.countdown) })}
  ${features(ctx.copy, { eyebrow: "Simple & secure" })}
  ${cardGrid({
    title: ctx.copy.categoriesTitle,
    lead: ctx.copy.categoriesLead,
    alt: true,
    eyebrow: "Categories",
    cards: categories.map((c) =>
      categoryCard(c, `${ROOT}vote.html?category=${encodeURIComponent(c.id)}`),
    ),
  })}
  ${cardGrid({
    title: ctx.copy.nomineesTitle,
    eyebrow: "Nominees",
    cols: 3,
    cards: nominees.slice(0, 8).map((n) =>
      nomineeCard(n, {
        category: categories.find((c) => c.id === n.categoryId),
        voteHref: `${ROOT}vote.html?nominee=${encodeURIComponent(n.id)}`,
      }),
    ),
    emptyText: "Nominees will appear here shortly.",
  })}
  ${occasionStrip(ctx.specials)}`;

/* Voting ended, winners not published yet */
const votingClosed = (ctx) =>
  html` ${hero({ ctx, copy: ctx.copy, aside: countdownPanel(ctx.countdown) })}
  ${features(ctx.copy, { eyebrow: "Up next" })} ${occasionStrip(ctx.specials)}`;

/* Winners are out */
const results = (ctx, { winners }) => {
  const [first] = winners;
  const spotlight = first
    ? html` <div class="oga-panel oga-spotlight">
        <p class="oga-panel__label">
          <i class="fa-solid fa-trophy"></i> ${first.category.name}
        </p>
        ${avatar(first.nominee, true)}
        <h2 class="mt-3 mb-1">${first.nominee.name}</h2>
        ${first.nominee.department
          ? html`<p class="mb-0">${first.nominee.department}</p>`
          : ""}
      </div>`
    : countdownPanel(null);
  return html` ${hero({ ctx, copy: ctx.copy, aside: spotlight })}
  ${cardGrid({
    title: ctx.copy.sectionTitle,
    lead: ctx.copy.sectionLead,
    eyebrow: "Winners",
    cols: 4,
    alt: true,
    cards: winners.map(winnerCard),
    emptyText: "Winners will be listed here shortly.",
  })}
  ${occasionStrip(ctx.specials)}`;
};

export const homeViews = {
  landing,
  nominations,
  voting,
  "voting-closed": votingClosed,
  results,
};
