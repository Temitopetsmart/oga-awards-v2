import { ROOT } from "../config/app.config.js";
import { html, initials, safeClass } from "../core/dom.js";

/** Photos are saved on the server as "uploads/…" paths; make them work from any page. Small cards use the thumbnail. */
const mediaUrl = (p) =>
  !p
    ? ""
    : /^(https?:)?\/\/|^data:/.test(p)
      ? p
      : ROOT + String(p).replace(/^\//, "");

const avatar = (n, large = false) => {
  const src = mediaUrl(large ? n.photo : (n.thumb ?? n.photo));
  return html`<span
    class="oga-avatar ${large ? "oga-avatar--lg" : ""}"
    aria-hidden="true"
    >${src
      ? html`<img src="${src}" alt="" loading="lazy" />`
      : initials(n.name)}</span
  >`;
};

export const categoryCard = (c, href) =>
  html` <a class="oga-card" href="${href}">
    <span class="oga-icon"
      ><i class="fa-solid ${safeClass(c.icon || "fa-award")}"></i
    ></span>
    <h3>${c.name}</h3>
    <p>${c.description}</p>
    <span class="oga-card__go"
      >Explore <i class="fa-solid fa-arrow-right"></i
    ></span>
  </a>`;

export const nomineeCard = (n, { category, voteHref } = {}) =>
  html` <article class="oga-card oga-card--hover nominee-card">
    ${avatar(n)}
    <h3>${n.name}</h3>
    <p>${[category?.name, n.department].filter(Boolean).join(" · ")}</p>
    ${voteHref
      ? html`<a
          class="btn btn-gold btn-sm mt-3 align-self-start"
          href="${voteHref}"
          ><i class="fa-solid fa-check-to-slot me-2"></i>Vote</a
        >`
      : ""}
  </article>`;

export const winnerCard = ({ category, nominee }) =>
  html` <article class="oga-card oga-card--hover winner-card">
    <span class="oga-badge"
      ><i class="fa-solid fa-trophy"></i> ${category.name}</span
    >
    <div class="winner-card__body">
      ${avatar(nominee)}
      <h3>${nominee.name}</h3>
      ${nominee.department ? html`<p>${nominee.department}</p>` : ""}
    </div>
  </article>`;

export const occasionCard = (o) =>
  html` <a
    class="oga-card"
    href="${/^https?:/.test(o.link.href) ? o.link.href : ROOT + o.link.href}"
  >
    <span class="oga-icon"
      ><i class="fa-solid ${safeClass(o.icon || "fa-star")}"></i
    ></span>
    <h3>${o.name}</h3>
    <p>${o.tagline}</p>
    <span class="oga-card__go"
      >${o.link.label || "Learn more"} <i class="fa-solid fa-arrow-right"></i
    ></span>
  </a>`;

export { avatar };
