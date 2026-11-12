import { html, toHTML } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { ROOT } from "../../../shared/js/config/app.config.js";

const day = (iso) =>
  iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" }) : "";

/** One announcement. The text is shown as plain text (escaped), with its line breaks kept. */
export const announcementCard = (a) => html`<article class="oga-card mb-3">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <h3 class="h5 mt-0 mb-2">
      ${a.pinned ? html`<i class="fa-solid fa-thumbtack me-2 text-gold"></i>` : ""}${a.title}
    </h3>
    <time class="small text-secondary" datetime="${a.at ?? ""}">${day(a.at)}</time>
  </div>
  <p class="mb-0" style="white-space:pre-line">${a.body}</p>
</article>`;

/** The face of the period: photo, name, period, a few words. */
export const faceCard = (f, { big = false } = {}) => html`<article class="oga-card h-100 ${big ? "text-center" : ""}">
  ${f.thumb || f.photo
    ? html`<img
        src="${mediaUrl(big ? f.photo || f.thumb : f.thumb || f.photo)}"
        alt="${f.name}"
        class="rounded-circle mb-3"
        width="${big ? 160 : 96}"
        height="${big ? 160 : 96}"
        style="object-fit:cover"
        loading="lazy"
      />`
    : ""}
  <p class="small text-gold text-uppercase mb-1">${f.headline || "Face of the period"} · ${f.period}</p>
  <h3 class="${big ? "h3" : "h5"} mt-0 mb-1">${f.name}</h3>
  <p class="small text-secondary mb-2">${f.category}${f.department ? ` · ${f.department}` : ""}</p>
  ${f.note ? html`<p class="mb-3">${f.note}</p>` : ""}
  <a class="btn btn-ghost btn-sm" href="${ROOT}nominee?id=${encodeURIComponent(f.nomineeId)}">See profile</a>
</article>`;

/** Adds the latest announcements and the Face of the period under the home page. Quietly does nothing if there are none. */
export async function mountHomeExtras(app) {
  const [ann, faces] = await Promise.all([
    getResource("announcements").catch(() => []),
    getResource("spotlight").catch(() => ({ featured: null, history: [] })),
  ]);
  const items = (Array.isArray(ann) ? ann : []).slice(0, 3);
  const face = faces?.featured ?? null;
  if (!items.length && !face) return;
  const section = document.createElement("section");
  section.className = "container py-5";
  section.id = "homeExtras";
  section.innerHTML = toHTML(html`<div class="row g-4">
    ${items.length
      ? html`<div class="${face ? "col-lg-7" : "col-12"}">
          <h2 class="h4 mb-3">Latest news</h2>
          ${items.map(announcementCard)}
          <a href="${ROOT}news" class="small">All announcements</a>
        </div>`
      : ""}
    ${face
      ? html`<div class="${items.length ? "col-lg-5" : "col-12"}">
          <h2 class="h4 mb-3">Face of the period</h2>
          ${faceCard(face, { big: true })}
          <a href="${ROOT}spotlight" class="small d-inline-block mt-2">Earlier faces</a>
        </div>`
      : ""}
  </div>`);
  app.append(section);
}