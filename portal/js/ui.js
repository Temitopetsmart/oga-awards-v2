import { ROOT } from "../../shared/js/config/app.config.js";
import { html } from "../../shared/js/core/dom.js";

/** A portal link that keeps an admin's read-only preview alive. */
export const href = (p, path = "") =>
  `${ROOT}portal/${path}${p.readOnly && p.viewAsId ? `?viewAs=${encodeURIComponent(p.viewAsId)}` : ""}`;

export const statusChip = (s) => {
  const map = {
    pending: ["wait", "Pending review"],
    approved: ["ok", "Approved"],
    rejected: ["bad", "Needs changes"],
  };
  const [tone, label] = map[s] ?? map.pending;
  return html`<span class="pt-chip pt-chip--${tone}">${label}</span>`;
};

export const emptyState = (icon, title, text = "") =>
  html`<div class="pt-empty">
    <i class="fa-solid ${icon}"></i>
    <h2 class="h5">${title}</h2>
    ${text ? html`<p class="mb-0">${text}</p>` : ""}
  </div>`;

export const card = (title, body, { icon = "", right = "" } = {}) =>
  html`<section class="pt-card">
    <div class="pt-card-head">
      <h2>
        ${icon
          ? html`<i class="fa-solid ${icon} me-2 text-gold"></i>`
          : ""}${title}
      </h2>
      ${right}
    </div>
    ${body}
  </section>`;
