import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { avatar } from "../../../shared/js/components/cards.js";
import {
  getCategories,
  getNominees,
} from "../../../shared/js/services/catalog.service.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { pageHero, pageBody, notice } from "../sections/page-sections.js";

export const title = "Nominee";

export default async function nominee(ctx, { params }) {
  const [nominees, cats] = await Promise.all([
    getNominees(ctx.occasion.id),
    getCategories(ctx.occasion.id),
  ]);
  const n = nominees.find((x) => x.id === params.get("id"));
  if (!n)
    return notice({
      icon: "fa-user-slash",
      title: "Nominee not found",
      text: "This profile does not exist or is not public yet.",
      actions: [{ label: "Browse nominees", href: "categories.html" }],
    });
  const cat = cats.find((c) => c.id === n.categoryId);
  document.title = `${n.name} — ${ctx.occasion.name} ${ctx.occasion.edition}`;
  const voting = ctx.state === "voting";

  return html` ${pageHero({
    eyebrow: cat?.name,
    title: n.name,
    crumbs: [
      { label: "Categories", href: "categories.html" },
      { label: n.name },
    ],
  })}
  ${pageBody(
    html` <div class="oga-profile">
      <aside class="oga-profile__side oga-card">
        ${avatar(n, true)}
        <h2 class="h5">${n.name}</h2>
        <p>${n.department ?? ""}</p>
      </aside>
      <div>
        <h2 class="h4">About ${n.name.split(" ")[0]}</h2>
        <p class="fs-5">
          ${n.bio || "This nominee’s story will appear here soon."}
        </p>
        <div class="d-flex flex-wrap gap-3 mt-4">
          ${voting
            ? html`<a
                class="btn btn-gold btn-lg"
                href="${ROOT}vote.html?nominee=${encodeURIComponent(n.id)}"
                ><i class="fa-solid fa-check-to-slot me-2"></i>Vote for
                ${n.name.split(" ")[0]}</a
              >`
            : ""}
          <button class="btn btn-ghost btn-lg" id="shareBtn">
            <i class="fa-solid fa-share-nodes me-2"></i>Share profile
          </button>
        </div>
      </div>
    </div>`,
    { width: "56rem" },
  )}`;
}

export function init(root) {
  root.querySelector("#shareBtn")?.addEventListener("click", async () => {
    const url = location.href.split("#")[0].replace(/[?&]preview=[^&]*/, "");
    try {
      if (navigator.share)
        await navigator.share({ title: document.title, url });
      else {
        await navigator.clipboard.writeText(url);
        notify.toast("success", "Link copied");
      }
    } catch {
      /* user cancelled */
    }
  });
}
