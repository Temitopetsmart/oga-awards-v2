import { APP } from "../config/app.config.js";
import { html, mount } from "../core/dom.js";
import { mediaUrl } from "../core/media.js";

/**
 * Advertising slot. <oga-ad-slot placement="site_footer"></oga-ad-slot> (or mountAdSlot(el, placement) for a plain element).
 *
 * - Asks the server which ads run in this place right now; nothing is hardcoded here (labels, speed and how many come from Admin).
 * - Several ads rotate on the admin-set timer. It pauses while the pointer or keyboard focus is on it, and while the tab is hidden.
 * - People who asked their device for reduced motion get NO automatic rotation and no fading: they use the arrows or dots.
 * - Never covers content (it sits in the page flow), never autoplays sound (a video only opens when someone presses Watch).
 * - Every ad is labelled. Showings are counted once per ad per visit, only while the slot is on screen; no personal data is sent.
 * - If there is nothing to show (or the server cannot be reached) the slot stays empty and takes no space.
 */
const API = () => APP.data.apiBase;
const reduced = () => matchMedia("(prefers-reduced-motion: reduce)").matches;
const SEEN_KEY = "oga-ads-seen";

const seenBefore = (place, id) => {
  try {
    return (JSON.parse(sessionStorage.getItem(SEEN_KEY)) ?? []).includes(`${place}:${id}`);
  } catch {
    return false;
  }
};
const markSeen = (place, id) => {
  try {
    const list = JSON.parse(sessionStorage.getItem(SEEN_KEY)) ?? [];
    list.push(`${place}:${id}`);
    sessionStorage.setItem(SEEN_KEY, JSON.stringify(list.slice(-200)));
  } catch {
    /* private mode: counting simply happens once per page */
  }
};

const EMBEDS = [
  "https://www.youtube-nocookie.com/embed/",
  "https://player.vimeo.com/video/",
];
const safeEmbed = (v) =>
  v && EMBEDS.some((p) => String(v.embed).startsWith(p)) ? v.embed : "";

function openVideo(ad) {
  const src = safeEmbed(ad.video);
  if (!src) return;
  const dlg = document.createElement("dialog");
  dlg.className = "oga-ad-video";
  dlg.setAttribute("aria-label", `Video: ${ad.headline}`);
  dlg.innerHTML = `<button type="button" class="oga-ad-video__close" aria-label="Close video">&times;</button>
    <iframe title="" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>`;
  dlg.querySelector("iframe").title = ad.headline; // set as text, never as markup
  dlg.querySelector("iframe").src = src;
  const close = () => {
    dlg.querySelector("iframe").src = "about:blank"; // stops the video
    dlg.close();
    dlg.remove();
  };
  dlg.querySelector("button").addEventListener("click", close);
  dlg.addEventListener("cancel", (e) => {
    e.preventDefault();
    close();
  });
  dlg.addEventListener("click", (e) => {
    if (e.target === dlg) close();
  });
  document.body.append(dlg);
  dlg.showModal();
}

/** `preview` (used by the admin) links straight to the advertiser and counts nothing. */
function slideHtml(ad, place, preview = false) {
  const href = preview
    ? ad.link
    : `${API()}/ads/click/${ad.id}?p=${encodeURIComponent(place)}`;
  return html`<div class="oga-ad__slide" data-id="${ad.id}" role="group" aria-roledescription="slide">
    <a class="oga-ad__link" href="${href}" target="_blank" rel="noopener sponsored nofollow">
      ${ad.banner
        ? html`<img class="oga-ad__banner" src="${mediaUrl(ad.banner)}" alt="" loading="lazy" />`
        : ""}
      <span class="oga-ad__body">
        ${ad.logo
          ? html`<img class="oga-ad__logo" src="${mediaUrl(ad.logo)}" alt="" loading="lazy" />`
          : ""}
        <span class="oga-ad__text">
          <strong class="oga-ad__headline">${ad.headline}</strong>
          ${ad.text ? html`<span class="oga-ad__desc">${ad.text}</span>` : ""}
          <span class="oga-ad__biz">${ad.business}</span>
        </span>
        <span class="oga-ad__cta">${ad.cta} <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></span>
      </span>
    </a>
    ${safeEmbed(ad.video)
      ? html`<button type="button" class="oga-ad__watch" data-video="${ad.id}">
          <i class="fa-solid fa-circle-play" aria-hidden="true"></i> Watch video
        </button>`
      : ""}
  </div>`;
}

/** Fills `el` with the ads for `placement`. Returns a function that stops everything (timers, observers). */
export async function mountAdSlot(el, placement, { count = true } = {}) {
  if (!el || APP.maintenance?.enabled) return () => {};
  let data;
  try {
    const res = await fetch(`${API()}/ads/serve?placement=${encodeURIComponent(placement)}`, { headers: { Accept: "application/json" } });
    data = res.ok ? await res.json() : null;
  } catch {
    data = null;
  }
  const ads = data?.ads ?? [];
  if (!ads.length) {
    el.replaceChildren();
    return () => {};
  }

  const many = ads.length > 1;
  const seconds = Math.max(3, Number(data.rotateSeconds) || 8);
  mount(
    el,
    html`<aside class="oga-ad${reduced() ? " oga-ad--still" : ""}" aria-label="${data.label}">
      <span class="oga-ad__label">${data.label}</span>
      <div class="oga-ad__track" aria-live="off">${ads.map((a) => slideHtml(a, placement))}</div>
      ${many
        ? html`<div class="oga-ad__nav">
            <button type="button" class="oga-ad__arrow" data-dir="-1" aria-label="Previous ad"><i class="fa-solid fa-chevron-left"></i></button>
            <span class="oga-ad__dots">${ads.map((a, i) => html`<button type="button" class="oga-ad__dot" data-i="${i}" aria-label="Show ad ${i + 1} of ${ads.length}"></button>`)}</span>
            <button type="button" class="oga-ad__arrow" data-dir="1" aria-label="Next ad"><i class="fa-solid fa-chevron-right"></i></button>
          </div>`
        : ""}
    </aside>`,
  );
  const root = el.querySelector(".oga-ad");
  const slides = [...root.querySelectorAll(".oga-ad__slide")];
  const dots = [...root.querySelectorAll(".oga-ad__dot")];
  let index = Math.floor(Math.random() * slides.length); // start anywhere so every ad gets a first turn
  let paused = false;
  let visible = false;

  const report = () => {
    if (!count || !visible) return;
    const ad = ads[index];
    if (seenBefore(placement, ad.id)) return;
    markSeen(placement, ad.id);
    fetch(`${API()}/ads/seen`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ placement, ids: [ad.id] }),
      keepalive: true,
    }).catch(() => {});
  };
  const show = (i) => {
    index = (i + slides.length) % slides.length;
    slides.forEach((s, n) => {
      const on = n === index;
      s.classList.toggle("is-active", on);
      s.setAttribute("aria-hidden", on ? "false" : "true");
      s.querySelectorAll("a,button").forEach((x) => (x.tabIndex = on ? 0 : -1));
    });
    dots.forEach((d, n) => d.setAttribute("aria-current", n === index ? "true" : "false"));
    report();
  };
  root.addEventListener("click", (e) => {
    const arrow = e.target.closest(".oga-ad__arrow");
    const dot = e.target.closest(".oga-ad__dot");
    const vid = e.target.closest("[data-video]");
    if (arrow) show(index + Number(arrow.dataset.dir));
    else if (dot) show(Number(dot.dataset.i));
    else if (vid) openVideo(ads.find((a) => String(a.id) === vid.dataset.video));
  });
  root.addEventListener("mouseenter", () => (paused = true));
  root.addEventListener("mouseleave", () => (paused = false));
  root.addEventListener("focusin", () => (paused = true));
  root.addEventListener("focusout", () => (paused = false));

  const io = new IntersectionObserver(
    (entries) => {
      visible = entries.some((e) => e.isIntersecting);
      if (visible) report();
    },
    { threshold: 0.5 },
  );
  io.observe(root);

  show(index);
  let timer = null;
  if (many && !reduced()) {
    timer = setInterval(() => {
      if (!paused && !document.hidden && visible) show(index + 1);
    }, seconds * 1000);
  }
  return () => {
    clearInterval(timer);
    io.disconnect();
  };
}

class OgaAdSlot extends HTMLElement {
  #stop = () => {};
  connectedCallback() {
    this.style.display = "block";
    mountAdSlot(this, this.getAttribute("placement") || "site_footer").then((stop) => (this.#stop = stop));
  }
  disconnectedCallback() {
    this.#stop();
  }
}
if (!customElements.get("oga-ad-slot")) customElements.define("oga-ad-slot", OgaAdSlot);

/** How an ad looks to visitors, for the admin review screen. Nothing is counted and nothing rotates. */
export const adPreviewHtml = (ad, label = "Ad") =>
  html`<aside class="oga-ad oga-ad--preview" aria-label="${label}">
    <span class="oga-ad__label">${label}</span>
    ${slideHtml({ ...ad, cta: ad.cta || "Learn more" }, "preview", true)}
  </aside>`;