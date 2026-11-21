import { APP, ROOT } from "../config/app.config.js";
import { html, mount } from "../core/dom.js";
import { getHomeContext, hasFeature } from "../services/event.service.js";

/** <oga-header></oga-header> — responsive navbar driven by APP.nav and the current event state. */
class OgaHeader extends HTMLElement {
  async connectedCallback() {
    this.#render(null); // instant, state-agnostic shell
    try {
      this.#render(await getHomeContext());
    } catch (e) {
      console.error(e);
    }
    this.onScroll = () =>
      this.querySelector(".oga-header")?.classList.toggle(
        "is-scrolled",
        scrollY > 8,
      );
    addEventListener("scroll", this.onScroll, { passive: true });
    this.onScroll();
  }
  disconnectedCallback() {
    removeEventListener("scroll", this.onScroll);
  }

  #render(ctx) {
    const current = location.pathname.split("/").pop() || "./";
    const items = APP.nav.filter(
      (n) =>
        (!n.states || (ctx && n.states.includes(ctx.state))) &&
        (!n.feature || (ctx && hasFeature(ctx.occasion, n.feature))),
    );

    const navItems = [];
    const groups = new Map();

    for (const item of items) {
      if (!item.group) {
        navItems.push({ type: "link", item });
        continue;
      }

      if (!groups.has(item.group)) {
        const group = { type: "group", label: item.group, items: [] };
        groups.set(item.group, group);
        navItems.push(group);
      }

      groups.get(item.group).items.push(item);
    }

    const isActive = (item) =>
      item.href === current || (item.href === "./" && current === "./");

    const cta = ctx?.stateInfo.cta;
    const showCta =
      cta && (!cta.feature || hasFeature(ctx.occasion, cta.feature));

    mount(
      this,
      html` <a class="oga-skip" href="#app">Skip to content</a>
        <header class="oga-header">
          <nav class="navbar navbar-expand-lg" aria-label="Main">
            <div class="container">
              <a class="navbar-brand" href="${ROOT}">
                <img
                  src="${ROOT}${APP.brand.logo}"
                  alt=""
                  width="36"
                  height="36"
                />
                <span
                  >${ctx
                    ? html`${ctx.occasion.name}
                        <em>${ctx.occasion.edition}</em>`
                    : APP.brand.name}</span
                >
              </a>
              <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#ogaNav"
                aria-controls="ogaNav"
                aria-expanded="false"
                aria-label="Toggle navigation"
              >
                <i class="fa-solid fa-bars"></i>
              </button>
              <div class="collapse navbar-collapse" id="ogaNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                  ${navItems.map((entry) =>
                    entry.type === "link"
                      ? html`<li class="nav-item">
                          <a
                            class="nav-link ${isActive(entry.item)
                              ? "active"
                              : ""}"
                            href="${ROOT}${entry.item.href}"
                            ${isActive(entry.item)
                              ? html`aria-current="page"`
                              : ""}
                            >${entry.item.label}</a
                          >
                        </li>`
                      : html`<li class="nav-item dropdown">
                          <a
                            class="nav-link dropdown-toggle ${entry.items.some(
                              isActive,
                            )
                              ? "active"
                              : ""}"
                            href="#"
                            role="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            >${entry.label}</a
                          >
                          <ul class="dropdown-menu dropdown-menu-end">
                            ${entry.items.map(
                              (item) => html`
                                <li>
                                  <a
                                    class="dropdown-item ${isActive(item)
                                      ? "active"
                                      : ""}"
                                    href="${ROOT}${item.href}"
                                    ${isActive(item)
                                      ? html`aria-current="page"`
                                      : ""}
                                    >${item.label}</a
                                  >
                                </li>
                              `,
                            )}
                          </ul>
                        </li>`,
                  )}
                </ul>

                <a
                  class="btn btn-ghost btn-sm ms-lg-3 mt-3 mt-lg-0"
                  href="${ROOT}login"
                  ><i class="fa-solid fa-right-to-bracket me-1"></i>Nominee
                  login</a
                >
                ${showCta
                  ? html`<a
                      class="btn btn-gold btn-sm ms-lg-3 mt-3 mt-lg-0"
                      href="${ROOT}${cta.href}"
                      >${cta.label}</a
                    >`
                  : ""}
              </div>
            </div>
          </nav>
        </header>`,
    );
    this.querySelector(".navbar-brand img")?.addEventListener("error", (e) =>
      e.target.remove(),
    );
  }
}

if (!customElements.get("oga-header"))
  customElements.define("oga-header", OgaHeader);
