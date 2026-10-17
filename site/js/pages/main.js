import "../../../shared/js/components/index.js";
import { html, mount } from "../../../shared/js/core/dom.js";
import { getHomeContext } from "../../../shared/js/services/event.service.js";
import { renderPreviewBar } from "../../../shared/js/components/preview-bar.js";

/**
 * Generic page runner. Each HTML page sets <body data-page="name">, and this loads
 * site/js/pages/<name>.js, which exports:
 *   title (string, optional) · default(ctx, { params }) -> html (or null) · init(root, ctx, { params }) (optional)
 */
const app = document.getElementById("app");
const page = document.body.dataset.page;

const skeleton = html` <div
  class="container py-5"
  aria-busy="true"
  aria-label="Loading"
>
  <div class="oga-skeleton mb-3" style="height:1.5rem;width:20%"></div>
  <div class="oga-skeleton mb-4" style="height:3rem;width:55%"></div>
  <div class="oga-skeleton" style="height:14rem"></div>
</div>`;

const failure = html` <div class="container" style="padding-block:6rem">
  <div class="oga-empty">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <h2 class="h4">We couldn’t load this page</h2>
    <p>Please check your connection and try again.</p>
    <button class="btn btn-gold" onclick="location.reload()">Reload</button>
  </div>
</div>`;

async function boot() {
  mount(app, skeleton);
  try {
    const [mod, ctx] = await Promise.all([
      import(`./${page}.js`),
      getHomeContext(),
    ]);
    const params = new URLSearchParams(location.search);
    const out = await mod.default(ctx, { params });
    if (out == null) return; // page redirected elsewhere
    mount(app, out);
    document.title = mod.title
      ? `${mod.title} — ${ctx.occasion.name} ${ctx.occasion.edition}`
      : document.title;
    document.body.dataset.state = ctx.state;
    await mod.init?.(app, ctx, { params });
    renderPreviewBar(ctx.state);
    if (location.hash)
      document.getElementById(location.hash.slice(1))?.scrollIntoView();
  } catch (err) {
    console.error(err);
    mount(app, failure);
  }
}
boot();
