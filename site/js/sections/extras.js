import { html, toHTML } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { ROOT } from "../../../shared/js/config/app.config.js";

const day = (iso) =>
  iso
    ? new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" })
    : "";

/** One announcement. The text is shown as plain text (escaped), with its line breaks kept. */
export const announcementCard = (a) =>
  html`<article class="oga-card mb-3">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <h3 class="h5 mt-0 mb-2">
        ${a.pinned
          ? html`<i class="fa-solid fa-thumbtack me-2 text-gold"></i>`
          : ""}${a.title}
      </h3>
      <time class="small text-secondary" datetime="${a.at ?? ""}"
        >${day(a.at)}</time
      >
    </div>
    <p class="mb-0" style="white-space:pre-line">${a.body}</p>
  </article>`;

/** Shared Face of the Period card. */
export const faceCard = (f, { big = false, home = false } = {}) =>
  html`<article
    class="oga-card oga-face-card ${big ? "oga-face-card--large" : ""} ${home
      ? "oga-face-card--home"
      : ""}"
  >
    ${f.thumb || f.photo
      ? html`<img
          src="${mediaUrl(big ? f.photo || f.thumb : f.thumb || f.photo)}"
          alt="${f.name}"
          class="oga-face-card__photo"
          loading="lazy"
        />`
      : html`<div class="oga-face-card__placeholder">
          <i class="fa-solid fa-user" aria-hidden="true"></i>
        </div>`}
    <div class="oga-face-card__details">
      <p class="small text-gold text-uppercase mb-1">
        ${f.headline || "Face of the period"} · ${f.period}
      </p>
      <h3 class="${big ? "h4" : "h5"} mt-0 mb-1">${f.name}</h3>
      <p class="small text-secondary mb-2">
        ${[f.category, f.department].filter(Boolean).join(" · ")}
      </p>
      ${f.note ? html`<p class="oga-face-card__note">${f.note}</p>` : ""}
      ${f.nomineeId
        ? html`<a
            class="btn btn-ghost btn-sm mt-2"
            href="${ROOT}nominee?id=${encodeURIComponent(f.nomineeId)}"
          >
            View profile
          </a>`
        : ""}
    </div>
  </article>`;

/** Mounts announcements below the homepage. The Face feature now belongs in the hero. */
export async function mountHomeExtras(app) {
  const ann = await getResource("announcements").catch(() => []);
  const items = (Array.isArray(ann) ? ann : []).slice(0, 3);

  if (!items.length) return;

  const section = document.createElement("section");
  section.className = "container py-5";
  section.id = "homeExtras";

  section.innerHTML = toHTML(html`
    <div class="row">
      <div class="col-lg-8 mx-auto">
        <h2 class="h4 mb-3">Latest news</h2>
        ${items.map(announcementCard)}
        <a href="${ROOT}news" class="small">All announcements</a>
      </div>
    </div>
  `);

  app.append(section);
}
