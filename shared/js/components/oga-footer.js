import { APP, ROOT } from "../config/app.config.js";
import { html, mount } from "../core/dom.js";
import { getHomeContext, hasFeature } from "../services/event.service.js";

/** <oga-footer></oga-footer> */
class OgaFooter extends HTMLElement {
  async connectedCallback() {
    this.#render(null);
    try {
      this.#render(await getHomeContext());
    } catch (e) {
      console.error(e);
    }
  }

  #render(ctx) {
    const links = APP.nav.filter(
      (n) =>
        (!n.states || (ctx && n.states.includes(ctx.state))) &&
        (!n.feature || (ctx && hasFeature(ctx.occasion, n.feature))),
    );
    const social = APP.social.filter((s) => s.href);
    const { email, phone } = APP.contact;

    mount(
      this,
      html` <footer class="oga-footer">
        <div class="container">
          <div class="row gy-5 gx-lg-5">
            <div class="col-lg-5">
              <a class="oga-footer__brand" href="${ROOT}index.html"
                >${APP.brand.name}</a
              >
              <p class="mt-3 mb-4">
                ${APP.brand.org} — celebrating excellence, leadership and impact
                across campus.
              </p>
              ${social.length
                ? html`<div class="oga-social">
                    ${social.map(
                      (s) =>
                        html`<a
                          href="${s.href}"
                          target="_blank"
                          rel="noopener"
                          aria-label="${s.label}"
                          ><i class="${s.icon}"></i
                        ></a>`,
                    )}
                  </div>`
                : ""}
            </div>
            <div class="col-6 col-lg-3">
              <h2 class="oga-footer__title">Explore</h2>
              <ul class="list-unstyled oga-footer__links">
                ${links.map(
                  (n) =>
                    html`<li><a href="${ROOT}${n.href}">${n.label}</a></li>`,
                )}
              </ul>
            </div>
            <div class="col-6 col-lg-4">
              <h2 class="oga-footer__title">Contact</h2>
              <ul class="list-unstyled oga-footer__links">
                ${email
                  ? html`<li>
                      <i class="fa-solid fa-envelope"></i
                      ><a href="mailto:${email}">${email}</a>
                    </li>`
                  : ""}
                ${phone
                  ? html`<li>
                      <i class="fa-solid fa-phone"></i
                      ><a href="tel:${phone}">${phone}</a>
                    </li>`
                  : ""}
              </ul>
            </div>
          </div>
          <div class="oga-footer__bottom">
            <span
              >© ${new Date().getFullYear()} ${APP.brand.name}. All rights
              reserved.</span
            >
            <span
              ><a href="${ROOT}privacy.html">Privacy</a> ·
              <a href="${ROOT}terms.html">Terms</a></span
            >
          </div>
        </div>
      </footer>`,
    );
  }
}

if (!customElements.get("oga-footer"))
  customElements.define("oga-footer", OgaFooter);
