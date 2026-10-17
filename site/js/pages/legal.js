import { html } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

export const title = "Legal";

/** Serves Privacy and Terms. The HTML page sets <body data-doc="privacy|terms">. */
export default async function legal() {
  const doc = (await getResource("legal"))[document.body.dataset.doc];
  if (!doc)
    return notice({
      icon: "fa-file-circle-question",
      title: "Document not found",
      actions: [{ label: "Back home", href: "index.html" }],
    });
  document.title = `${doc.title} — OGA`;
  return html` ${pageHero({
    title: doc.title,
    lead: `Last updated ${formatDate(doc.updated)}`,
    crumbs: [{ label: doc.title }],
  })}
  ${pageBody(
    html` ${doc.draft
        ? html`<div class="alert alert-oga mb-4" role="note">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>Draft text —
            have this reviewed before launch.
          </div>`
        : ""}
      <div class="oga-prose">
        ${doc.sections.map(
          (s) =>
            html`<h2>${s.heading}</h2>
              <p>${s.body}</p>`,
        )}
      </div>`,
  )}`;
}
