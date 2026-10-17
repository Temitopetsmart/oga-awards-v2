import { html } from "../../../shared/js/core/dom.js";
import { safeClass } from "../../../shared/js/core/dom.js";
import {
  getCategories,
  getNominees,
} from "../../../shared/js/services/catalog.service.js";
import {
  pageHero,
  pageBody,
  stateGate,
  nomineeListCard,
} from "../sections/page-sections.js";

export const title = "Categories & Nominees";

export default async function categories(ctx) {
  const hero = pageHero({
    eyebrow: "The awards",
    title: "Categories & nominees",
    lead: "Explore every category and meet the people in the running.",
    crumbs: [{ label: "Categories" }],
  });
  const closed = stateGate(
    ctx,
    ["nominations", "voting", "voting-closed", "results"],
    {
      default: {
        icon: "fa-hourglass-half",
        title: "Categories are coming soon",
        text: "We’ll reveal the categories and nominees once nominations open.",
        actions: [{ label: "Back home", href: "index.html" }],
      },
    },
  );
  if (closed) return html`${hero}${closed}`;

  const [cats, nominees] = await Promise.all([
    getCategories(ctx.occasion.id),
    getNominees(ctx.occasion.id),
  ]);
  const voting = ctx.state === "voting";

  return html`${hero}${pageBody(
    html` <div class="oga-toolbar">
        <div class="oga-chips" role="navigation" aria-label="Jump to category">
          ${cats.map(
            (c) => html`<a class="oga-chip" href="#${c.id}">${c.name}</a>`,
          )}
        </div>
        <div class="oga-search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input
            id="nomineeSearch"
            type="search"
            class="form-control"
            placeholder="Search nominees"
            aria-label="Search nominees"
          />
        </div>
      </div>
      ${cats.length
        ? cats.map((c) => {
            const list = nominees.filter((n) => n.categoryId === c.id);
            return html` <section
              class="oga-cat"
              id="${c.id}"
              aria-labelledby="h-${c.id}"
            >
              <header class="oga-cat__head">
                <span class="oga-icon"
                  ><i class="fa-solid ${safeClass(c.icon || "fa-award")}"></i
                ></span>
                <div>
                  <h2 id="h-${c.id}">${c.name}</h2>
                  <p>${c.description}</p>
                </div>
                <span class="oga-badge oga-badge--muted"
                  >${list.length} nominee${list.length === 1 ? "" : "s"}</span
                >
              </header>
              <div class="row g-4">
                ${list.length
                  ? list.map(
                      (n) =>
                        html`<div
                          class="col-sm-6 col-lg-4 col-xl-3"
                          data-nominee
                        >
                          ${nomineeListCard(n, { category: c, voting })}
                        </div>`,
                    )
                  : html`<div class="col-12">
                      <div class="oga-empty mb-0">No nominees yet.</div>
                    </div>`}
              </div>
            </section>`;
          })
        : html`<div class="oga-empty">
            <i class="fa-regular fa-folder-open"></i>
            <p class="mb-0">No categories yet.</p>
          </div>`}
      <div class="oga-empty d-none" id="noResults">
        No nominees match your search.
      </div>`,
  )}`;
}

export function init(root) {
  const input = root.querySelector("#nomineeSearch");
  input?.addEventListener("input", () => {
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    root.querySelectorAll("[data-nominee]").forEach((el) => {
      const hit =
        !q || el.querySelector("[data-name]").dataset.name.includes(q);
      el.classList.toggle("d-none", !hit);
      if (hit) shown += 1;
    });
    root
      .querySelectorAll(".oga-cat")
      .forEach((sec) =>
        sec.classList.toggle(
          "d-none",
          Boolean(q) && !sec.querySelector("[data-nominee]:not(.d-none)"),
        ),
      );
    root
      .querySelector("#noResults")
      .classList.toggle("d-none", !q || shown > 0);
  });
}
