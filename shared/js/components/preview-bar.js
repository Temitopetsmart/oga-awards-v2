import { APP, STATES } from "../config/app.config.js";
import { html, mount } from "../core/dom.js";

/** Dev helper: shows a bar to switch home states when the URL has ?preview=... */
export function renderPreviewBar(currentState) {
  if (!APP.allowPreview || !new URLSearchParams(location.search).has("preview"))
    return;
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
