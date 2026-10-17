import { ROOT } from "../../../shared/js/config/app.config.js";
import { html, safeClass } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { avatar } from "../../../shared/js/components/cards.js";

/** Banner at the top of every inner page. */
export const pageHero = ({ eyebrow = "", title, lead = "", crumbs = [] }) =>
  html` <section class="oga-pagehero">
    <div class="container">
      <nav aria-label="Breadcrumb">
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="${ROOT}index.html">Home</a></li>
          ${crumbs.map((c) =>
            c.href
              ? html`<li class="breadcrumb-item">
                  <a href="${ROOT}${c.href}">${c.label}</a>
                </li>`
              : html`<li class="breadcrumb-item active" aria-current="page">
                  ${c.label}
                </li>`,
          )}
        </ol>
      </nav>
      ${eyebrow
        ? html`<span class="oga-eyebrow d-block mb-2">${eyebrow}</span>`
        : ""}
      <h1>${title}</h1>
      ${lead ? html`<p class="oga-pagehero__lead">${lead}</p>` : ""}
    </div>
  </section>`;

/** Centered message card: closed pages, not-found, empty states, success messages. */
export const notice = ({
  icon = "fa-circle-info",
  title,
  text = "",
  actions = [],
  extra = "",
}) =>
  html` <section class="oga-section">
    <div class="container" style="max-width:40rem">
      <div class="oga-panel">
        <span class="oga-icon mb-3"
          ><i class="fa-solid ${safeClass(icon)}"></i
        ></span>
        <h2 class="h3">${title}</h2>
        <p>${text}</p>
        ${extra}
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
          ${actions.map(
            (a) =>
              html`<a
                class="btn ${a.variant === "ghost" ? "btn-ghost" : "btn-gold"}"
                href="${ROOT}${a.href}"
                >${a.label}</a
              >`,
          )}
        </div>
      </div>
    </div>
  </section>`;

/** Returns a notice when the current home state isn't allowed for a page; otherwise null. */
export function stateGate(ctx, allowed, messages) {
  if (allowed.includes(ctx.state)) return null;
  return notice(messages[ctx.state] ?? messages.default);
}

const LABELS = {
  nominationsOpen: "Nominations open",
  nominationsClose: "Nominations close",
  votingOpen: "Voting opens",
  votingClose: "Voting closes",
  resultsPublish: "Winners announced",
  ceremony: "Awards night",
};

/** Key dates for an occasion, marked past / next / upcoming. */
export function timeline(occasion) {
  const t = occasion.timeline ?? {};
  const rows = Object.entries(LABELS)
    .filter(([k]) => t[k])
    .map(([k, label]) => ({ label, at: t[k] }))
    .sort((a, b) => new Date(a.at) - new Date(b.at));
  if (!rows.length) return "";
  const nextIndex = rows.findIndex((r) => new Date(r.at) > Date.now());
  return html`<ol class="oga-timeline">
    ${rows.map(
      (r, i) =>
        html` <li
          class="${nextIndex === -1 || i < nextIndex
            ? "is-past"
            : i === nextIndex
              ? "is-next"
              : ""}"
        >
          <span class="oga-timeline__dot"></span>
          <div>
            <strong>${r.label}</strong
            ><span>${formatDate(r.at, { dateStyle: "full" })}</span>
          </div>
        </li>`,
    )}
  </ol>`;
}

export const nomineeListCard = (n, { category, voting = false } = {}) =>
  html` <article class="oga-card oga-card--hover nominee-card">
    ${avatar(n)}
    <h3 data-name="${n.name.toLowerCase()}">${n.name}</h3>
    <p>${[category?.name, n.department].filter(Boolean).join(" · ")}</p>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <a
        class="btn btn-ghost btn-sm"
        href="${ROOT}nominee.html?id=${encodeURIComponent(n.id)}"
        >Profile</a
      >
      ${voting
        ? html`<a
            class="btn btn-gold btn-sm"
            href="${ROOT}vote.html?nominee=${encodeURIComponent(n.id)}"
            >Vote</a
          >`
        : ""}
    </div>
  </article>`;

/** Page body wrapper that sits under a pageHero. */
export const pageBody = (inner, { width = "" } = {}) =>
  html` <section class="oga-section pt-0">
    <div class="container" ${width ? html`style="max-width:${width}"` : ""}>
      ${inner}
    </div>
  </section>`;
