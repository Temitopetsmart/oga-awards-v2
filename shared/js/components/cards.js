import { ROOT } from "../config/app.config.js";
import { html, initials, safeClass } from "../core/dom.js";

/** Photos are saved on the server as "uploads/…" paths; make them work from any page. Small cards use the thumbnail. */
const mediaUrl = (p) =>
  !p
    ? ""
    : /^(https?:)?\/\/|^data:/.test(p)
      ? p
      : ROOT + String(p).replace(/^\//, "");

/**
 * Shared profile image.
 * Uses the thumbnail for cards and the original photo for large displays.
 * Falls back to initials when no photograph is available.
 */
export const profileImage = (person, { size = "card" } = {}) => {
  const variant = ["card", "large", "compact"].includes(size) ? size : "card";

  const src = mediaUrl(
    variant === "large" ? person?.photo : person?.thumb || person?.photo,
  );

  return html`<span
    class="oga-avatar oga-profile-image oga-avatar--${variant === "large"
      ? "lg"
      : variant}"
    role="img"
    aria-label="${person?.name || "Profile photo"}"
  >
    ${src
      ? html`<img src="${src}" alt="" loading="lazy" />`
      : initials(person?.name || "")}
  </span>`;
};

/** Backward-compatible helper for existing nominee and winner cards. */
const avatar = (person, large = false) =>
  profileImage(person, { size: large ? "large" : "card" });

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
  html`<article class="oga-card oga-card--hover oga-person-card nominee-card">
    <div class="oga-person-card__media">${avatar(n, true)}</div>
    <div class="oga-person-card__body">
      <h3>${n.name}</h3>
      <p>${[category?.name, n.department].filter(Boolean).join(" · ")}</p>
      ${voteHref
        ? html`<a
            class="btn btn-gold btn-sm mt-2 align-self-start"
            href="${voteHref}"
            ><i class="fa-solid fa-check-to-slot me-2"></i>Vote</a
          >`
        : ""}
    </div>
  </article>`;

export const winnerCard = ({ category, nominee }) =>
  html`<article class="oga-card oga-card--hover oga-person-card winner-card">
    <div class="oga-person-card__media">${avatar(nominee, true)}</div>
    <div class="oga-person-card__body">
      <span class="oga-badge">
        <i class="fa-solid fa-trophy"></i> ${category.name}
      </span>
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
