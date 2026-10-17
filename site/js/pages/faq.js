import { html } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "FAQ";

export default async function faq() {
  const faqs = await getResource("faqs");
  const groups = [...new Set(faqs.map((f) => f.group))];
  return html` ${pageHero({
    eyebrow: "Help",
    title: "Frequently asked questions",
    lead: "Quick answers about nominations, voting and tickets.",
    crumbs: [{ label: "FAQ" }],
  })}
  ${pageBody(
    html` <div class="oga-toolbar">
        <div class="oga-chips" id="faqChips">
          <button type="button" class="oga-chip is-active" data-group="">
            All
          </button>
          ${groups.map(
            (g) =>
              html`<button type="button" class="oga-chip" data-group="${g}">
                ${g}
              </button>`,
          )}
        </div>
        <div class="oga-search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input
            id="faqSearch"
            type="search"
            class="form-control"
            placeholder="Search questions"
            aria-label="Search questions"
          />
        </div>
      </div>
      <div class="accordion" id="faqList">
        ${faqs.map(
          (f, i) =>
            html` <div
              class="accordion-item"
              data-group="${f.group}"
              data-text="${(f.q + " " + f.a).toLowerCase()}"
            >
              <h2 class="accordion-header">
                <button
                  class="accordion-button collapsed"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#faq-${i}"
                  aria-expanded="false"
                  aria-controls="faq-${i}"
                >
                  ${f.q}
                </button>
              </h2>
              <div
                id="faq-${i}"
                class="accordion-collapse collapse"
                data-bs-parent="#faqList"
              >
                <div class="accordion-body">${f.a}</div>
              </div>
            </div>`,
        )}
      </div>
      <div class="oga-empty d-none" id="faqEmpty">
        No questions match your search.
      </div>`,
    { width: "52rem" },
  )}`;
}

export function init(root) {
  let group = "";
  const search = root.querySelector("#faqSearch");
  const apply = () => {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    root.querySelectorAll(".accordion-item").forEach((el) => {
      const hit =
        (!group || el.dataset.group === group) &&
        (!q || el.dataset.text.includes(q));
      el.classList.toggle("d-none", !hit);
      if (hit) shown += 1;
    });
    root.querySelector("#faqEmpty").classList.toggle("d-none", shown > 0);
  };
  search.addEventListener("input", apply);
  root.querySelector("#faqChips").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-group]");
    if (!btn) return;
    group = btn.dataset.group;
    root
      .querySelectorAll("#faqChips .oga-chip")
      .forEach((c) => c.classList.toggle("is-active", c === btn));
    apply();
  });
}
