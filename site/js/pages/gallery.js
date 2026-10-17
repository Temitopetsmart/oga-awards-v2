import { html, mount, safeClass } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "Gallery";
let items = [];

const tileInner = (g) =>
  g.src
    ? html`<img src="${g.src}" alt="${g.title}" loading="lazy" />`
    : html`<div>
        <i class="fa-solid ${safeClass(g.icon || "fa-image")}"></i
        ><span>${g.title}</span>
      </div>`;

export default async function gallery() {
  items = await getResource("gallery");
  const editions = [...new Set(items.map((g) => g.edition))];
  return html` ${pageHero({
    eyebrow: "Memories",
    title: "Gallery",
    lead: "Moments from past editions of the awards.",
    crumbs: [{ label: "Gallery" }],
  })}
  ${pageBody(
    html` <div class="oga-chips mb-4" id="galleryChips">
        <button class="oga-chip is-active" data-edition="">All</button>
        ${editions.map(
          (e) =>
            html`<button class="oga-chip" data-edition="${e}">${e}</button>`,
        )}
      </div>
      <div class="row g-4" id="galleryGrid">
        ${items.map(
          (g) =>
            html`<div class="col-6 col-lg-4" data-edition="${g.edition}">
              <button
                class="oga-tile"
                data-open="${g.id}"
                aria-label="Open ${g.title}"
              >
                ${tileInner(g)}
              </button>
            </div>`,
        )}
      </div>
      <div
        class="modal fade"
        id="lightbox"
        tabindex="-1"
        aria-labelledby="lightboxTitle"
        aria-hidden="true"
      >
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title h5" id="lightboxTitle"></h2>
              <button
                type="button"
                class="btn-close"
                data-bs-dismiss="modal"
                aria-label="Close"
              ></button>
            </div>
            <div class="modal-body">
              <div id="lightboxBody"></div>
              <p class="mt-3 mb-0" id="lightboxCaption"></p>
            </div>
          </div>
        </div>
      </div>`,
  )}`;
}

export function init(root) {
  root.querySelector("#galleryChips").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-edition]");
    if (!btn) return;
    root
      .querySelectorAll("#galleryChips .oga-chip")
      .forEach((c) => c.classList.toggle("is-active", c === btn));
    root
      .querySelectorAll("#galleryGrid [data-edition]")
      .forEach((c) =>
        c.classList.toggle(
          "d-none",
          Boolean(btn.dataset.edition) &&
            c.dataset.edition !== btn.dataset.edition,
        ),
      );
  });
  const modalEl = root.querySelector("#lightbox");
  const modal = window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;
  root.querySelector("#galleryGrid").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-open]");
    if (!btn || !modal) return;
    const g = items.find((x) => x.id === btn.dataset.open);
    root.querySelector("#lightboxTitle").textContent =
      `${g.title} · ${g.edition}`;
    mount(
      root.querySelector("#lightboxBody"),
      html`<div class="oga-tile" style="cursor:default">${tileInner(g)}</div>`,
    );
    root.querySelector("#lightboxCaption").textContent = g.caption ?? "";
    modal.show();
  });
}
