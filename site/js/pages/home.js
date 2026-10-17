import "../../../shared/js/components/index.js";
import { html, mount } from "../../../shared/js/core/dom.js";
import { getHomeContext } from "../../../shared/js/services/event.service.js";
import {
  getCategories,
  getNominees,
  getWinners,
} from "../../../shared/js/services/catalog.service.js";
import { renderPreviewBar } from "../../../shared/js/components/preview-bar.js";
import { homeViews } from "../views/home.views.js";

const app = document.getElementById("app");
let busy = false;

/** Load only the data each state actually needs. */
async function loadData({ state, occasion }) {
  const id = occasion.id;
  switch (state) {
    case "nominations":
      return { categories: await getCategories(id) };
    case "voting": {
      const [categories, nominees] = await Promise.all([
        getCategories(id),
        getNominees(id),
      ]);
      return { categories, nominees };
    }
    case "results":
      return { winners: await getWinners(id) };
    default:
      return {};
  }
}

const skeleton = () =>
  html` <div class="container py-5" aria-busy="true" aria-label="Loading">
    <div class="oga-skeleton mb-4" style="height:2rem;width:30%"></div>
    <div class="oga-skeleton mb-3" style="height:4rem;width:70%"></div>
    <div class="oga-skeleton" style="height:12rem"></div>
  </div>`;

const errorState = () =>
  html` <div class="container py-6 text-center" style="padding-block:6rem">
    <div class="oga-empty">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <h2 class="h4">We couldn’t load this page</h2>
      <p>Please check your connection and try again.</p>
      <button class="btn btn-gold" data-retry>Try again</button>
    </div>
  </div>`;

async function load() {
  if (busy) return;
  busy = true;
  mount(app, skeleton());
  try {
    const ctx = await getHomeContext();
    const data = await loadData(ctx);
    document.title = `${ctx.occasion.name} ${ctx.occasion.edition} — ${ctx.stateInfo.label}`;
    document.body.dataset.state = ctx.state;
    mount(app, homeViews[ctx.state](ctx, data));
    renderPreviewBar(ctx.state);
  } catch (err) {
    console.error(err);
    mount(app, errorState());
    app.querySelector("[data-retry]")?.addEventListener("click", () => {
      busy = false;
      load();
    });
  } finally {
    busy = false;
  }
}

// When a countdown hits zero the state may have changed — re-resolve and re-render.
app.addEventListener("countdown:end", () => setTimeout(load, 500));
load();
