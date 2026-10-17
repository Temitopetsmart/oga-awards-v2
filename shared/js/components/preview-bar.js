import { APP, STATES } from "../config/app.config.js";
import { html, mount } from "../core/dom.js";
import { previewKey, withPreview } from "../core/nav.js";

let linksPatched = false;

/** Dev helper: when the URL has ?preview=…, show a state switcher and keep the preview on every internal link. */
export function renderPreviewBar(currentState) {
  if (!APP.allowPreview || !previewKey()) return;

  if (!linksPatched) {
    linksPatched = true;
    document.addEventListener(
      "click",
      (e) => {
        const a = e.target.closest?.("a[href]");
        if (!a || a.target === "_blank" || a.closest("#oga-preview")) return;
        const href = a.getAttribute("href");
        if (!href || /^(#|mailto:|tel:|javascript:)/.test(href)) return;
        a.href = withPreview(a.href);
      },
      true,
    );
  }

  document.body.style.paddingBottom = "6rem"; // keep the floating bar from covering page content
  let bar = document.getElementById("oga-preview");
  if (!bar) {
    bar = document.createElement("div");
    bar.id = "oga-preview";
    bar.className = "oga-preview";
    document.body.append(bar);
  }
  mount(
    bar,
    html` <strong><i class="fa-solid fa-eye"></i> Preview</strong>
      ${Object.entries(STATES).map(
        ([key, s]) =>
          html`<a
            class="${key === currentState ? "is-active" : ""}"
            href="?preview=${key}"
            >${s.label}</a
          >`,
      )}
      <a href="${location.pathname}" aria-label="Exit preview"
        ><i class="fa-solid fa-xmark"></i
      ></a>`,
  );
}
