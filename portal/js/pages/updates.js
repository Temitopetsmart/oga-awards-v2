import { html } from "../../../shared/js/core/dom.js";
import { getNomineeAnnouncements } from "../../../shared/js/services/auth.service.js";
import { card, emptyState } from "../ui.js";

export const title = "Updates";

export default async function updates(p) {
  if (p.readOnly)
    return card("Updates", emptyState("fa-eye", "Not shown in preview"), {
      icon: "fa-bell",
    });
  const list = await getNomineeAnnouncements().catch(() => []);
  if (!list.length)
    return card(
      "Updates",
      emptyState(
        "fa-bell-slash",
        "No updates yet",
        "Messages from the organisers to nominees appear here.",
      ),
      { icon: "fa-bell" },
    );
  return html`<div class="d-grid gap-3">
    ${list.map((a) =>
      card(
        html`${a.pinned ? "📌 " : ""}${a.title}`,
        html`<time class="small text-secondary d-block mb-2"
            >${a.at
              ? new Date(a.at).toLocaleDateString(undefined, {
                  dateStyle: "long",
                })
              : ""}</time
          >
          <p class="mb-0" style="white-space:pre-line">${a.body}</p>`,
      ),
    )}
  </div>`;
}
