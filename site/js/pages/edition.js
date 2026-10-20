import { html } from "../../../shared/js/core/dom.js";
import { getEditions } from "../../../shared/js/services/event.service.js";
import {
  getWinners,
  getCommittee,
} from "../../../shared/js/services/catalog.service.js";
import { winnerCard } from "../../../shared/js/components/cards.js";
import { avatar } from "../../../shared/js/components/cards.js";
import {
  pageHero,
  pageBody,
  notice,
  timeline,
} from "../sections/page-sections.js";
import { cardGrid } from "../sections/sections.js";

export const title = "Edition";

/** One past edition: winners, key dates and the committee that ran it. */
export default async function edition(ctx, { params }) {
  const { editions } = await getEditions();
  const e = editions.find(
    (x) => x.id === params.get("id") && x.status !== "draft",
  );
  if (!e)
    return notice({
      icon: "fa-clock-rotate-left",
      title: "Edition not found",
      text: "It may not be published yet.",
      actions: [{ label: "Hall of Fame", href: "editions.html" }],
    });
  document.title = `${e.name} ${e.edition} — Hall of Fame`;
  const [winners, committee] = await Promise.all([
    getWinners(e.id),
    getCommittee(e.id),
  ]);

  return html` ${pageHero({
    eyebrow: `Edition ${e.year ?? ""}`,
    title: `${e.name} ${e.edition}`,
    lead: e.tagline,
    crumbs: [
      { label: "Hall of Fame", href: "editions.html" },
      { label: `${e.name} ${e.edition}` },
    ],
  })}
  ${cardGrid({
    title: "Winners",
    eyebrow: "The gold",
    cols: 4,
    cards: winners.map(winnerCard),
    emptyText: "Winners for this edition haven’t been published.",
  })}
  ${pageBody(
    html`<div class="row gy-5 gx-lg-5">
      <div class="col-lg-5">
        <h2 class="h4 mb-4">Key dates</h2>
        ${timeline(e)}
      </div>
      <div class="col-lg-7">
        <h2 class="h4 mb-4">The committee</h2>
        ${committee.members.length
          ? html`<div class="row g-3">
              ${committee.members.map(
                (m) =>
                  html`<div class="col-sm-6">
                    <div
                      class="oga-card flex-row align-items-center gap-3"
                      style="height:auto"
                    >
                      ${avatar(m)}
                      <div>
                        <strong class="d-block">${m.name}</strong
                        ><span class="text-secondary small">${m.role}</span>
                      </div>
                    </div>
                  </div>`,
              )}
            </div>`
          : html`<p class="text-secondary">
              No committee listed for this edition.
            </p>`}
      </div>
    </div>`,
  )}`;
}
