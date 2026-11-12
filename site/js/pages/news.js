import { html } from "../../../shared/js/core/dom.js";
import { getResource } from "../../../shared/js/core/http.js";
import { pageHero, notice } from "../sections/page-sections.js";
import { announcementCard } from "../sections/extras.js";

export const title = "News";

export default async function news() {
  const list = await getResource("announcements").catch(() => []);
  const hero = pageHero({
    eyebrow: "Updates",
    title: "News & announcements",
    lead: "The latest from the organisers.",
    crumbs: [{ label: "News" }],
  });
  if (!list.length) {
    return html`${hero}${notice({
      icon: "fa-bullhorn",
      title: "Nothing new yet",
      text: "Announcements will appear here.",
      actions: [{ label: "Back home", href: "./" }],
    })}`;
  }
  return html`${hero}<section class="container pb-5">${list.map(announcementCard)}</section>`;
}