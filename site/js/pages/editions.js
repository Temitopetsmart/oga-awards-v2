import { html } from "../../../shared/js/core/dom.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { getEditions } from "../../../shared/js/services/event.service.js";
import {
  getWinners,
  getCategories,
} from "../../../shared/js/services/catalog.service.js";
import { pageHero, pageBody } from "../sections/page-sections.js";
import { cardGrid } from "../sections/sections.js";

export const title = "Hall of Fame";

/** Past editions of the awards, newest first. */
export default async function editions(ctx) {
  const { active, editions: all } = await getEditions();
  const past = all.filter(
    (e) => e.status === "completed" || e.status === "archived",
  );
  const rows = await Promise.all(
    past.map(async (e) => ({
      e,
      winners: (await getWinners(e.id)).length,
      categories: (await getCategories(e.id)).length,
    })),
  );
  const current = all.find((e) => e.id === active);

  return html` ${pageHero({
    eyebrow: "Hall of Fame",
    title: "Every edition, remembered",
    lead: "Winners, committees and moments from past editions of the awards.",
    crumbs: [{ label: "Hall of Fame" }],
  })}
  ${current
    ? pageBody(
        html`<a
          class="oga-card oga-card--hover flex-row align-items-center gap-3"
          href="${ROOT}index.html"
          style="height:auto"
        >
          <span class="oga-icon"
            ><i class="fa-solid fa-flag-checkered"></i
          ></span>
          <span class="flex-grow-1"
            ><strong class="d-block"
              >${current.name} ${current.edition} is happening now</strong
            ><span class="text-secondary">See what’s on right now</span></span
          >
          <i class="fa-solid fa-arrow-right text-gold"></i
        ></a>`,
      )
    : ""}
  ${rows.length
    ? cardGrid({
        title: "Past editions",
        eyebrow: "Archive",
        cols: 4,
        cards: rows.map(
          ({ e, winners, categories }) =>
            html` <a
              class="oga-card"
              href="${ROOT}edition.html?id=${encodeURIComponent(e.id)}"
            >
              <span class="oga-badge mb-2">${e.year ?? ""}</span>
              <h3 class="mt-1">${e.name} ${e.edition}</h3>
              <p>
                ${winners} winner${winners === 1 ? "" : "s"} · ${categories}
                categor${categories === 1 ? "y" : "ies"}
              </p>
              ${e.timeline?.ceremony
                ? html`<p class="small mt-1">
                    ${formatDate(e.timeline.ceremony, { dateStyle: "long" })}
                  </p>`
                : ""}
              <span class="oga-card__go"
                >View edition <i class="fa-solid fa-arrow-right"></i></span
            ></a>`,
        ),
      })
    : cardGrid({
        title: "Past editions",
        cards: [],
        emptyText:
          "Past editions will appear here once an edition is completed.",
      })}`;
}
