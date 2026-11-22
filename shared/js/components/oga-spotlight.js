import { APP, ROOT } from "../config/app.config.js";
import { html, mount } from "../core/dom.js";
import { mediaUrl } from "../core/media.js";

/**
 * Face of the moment. <oga-spotlight variant="page"></oga-spotlight> (or variant="home" for the compact card).
 *
 * - The server picks one random nominee or committee member for each time slot the admin sets (seconds to months).
 *   Everybody sees the same person until the slot ends, then the card changes by itself. Nothing here says who is next.
 * - It asks for the next face just after the slot ends (plus a small random delay so thousands of visitors do not
 *   all arrive at the same instant), and again when a hidden tab comes back.
 * - The change is a little reveal: earlier faces flash by, then the new face appears. People who asked their device
 *   for reduced motion get the new face with no animation.
 * - If the spotlight is off, or nobody is eligible yet, it stays empty and takes no space.
 */
const API = () => APP.data.apiBase;
const reduced = () => matchMedia("(prefers-reduced-motion: reduce)").matches;

const clock = (ms) => {
  const s = Math.max(0, Math.round(ms / 1000));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (d >= 1) return `${d}d ${h}h`;
  if (h >= 1) return `${h}h ${String(m).padStart(2, "0")}m`;
  return `${String(m).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;
};

const initials = (name) =>
  String(name ?? "")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join("");

const photoOf = (f, big) => {
  const src = big ? f.photo || f.thumb : f.thumb || f.photo;
  return src ? mediaUrl(src) : "";
};

const profileLink = (f) =>
  f.type === "nominee" && f.nomineeId
    ? html`<a
        class="btn btn-gold btn-sm"
        href="${ROOT}nominee?id=${encodeURIComponent(f.nomineeId)}"
        ><i class="fa-solid fa-user me-1"></i>View profile</a
      >`
    : f.type === "committee"
      ? html`<a class="btn btn-ghost btn-sm" href="${ROOT}committee"
          ><i class="fa-solid fa-people-group me-1"></i>Meet the committee</a
        >`
      : "";

const faceHtml = (d, variant) => {
  const f = d.face;
  const src = photoOf(f, true);
  return html`<article class="oga-spot__card" data-face="${f.type}:${f.name}">
    <figure class="oga-spot__photo">
      ${src
        ? html`<img src="${src}" alt="${f.name}" />`
        : html`<span class="oga-spot__initials" aria-hidden="true"
            >${initials(f.name)}</span
          >`}
      <span class="oga-spot__role"
        ><i
          class="fa-solid ${f.type === "committee"
            ? "fa-people-group"
            : "fa-award"} me-1"
        ></i
        >${f.type === "committee" ? "Committee" : "Nominee"}</span
      >
      <span
        class="oga-spot__spark oga-spot__spark--a"
        aria-hidden="true"
      ></span>
      <span
        class="oga-spot__spark oga-spot__spark--b"
        aria-hidden="true"
      ></span>
      <span
        class="oga-spot__spark oga-spot__spark--c"
        aria-hidden="true"
      ></span>
    </figure>
    <div class="oga-spot__body">
      <h3 class="oga-spot__name">${f.name}</h3>
      ${f.nickname ? html`<p class="oga-spot__nick">“${f.nickname}”</p>` : ""}
      <p class="oga-spot__line">
        ${[f.line, f.extra].filter(Boolean).join(" · ")}
      </p>
      ${f.bio
        ? html`<blockquote class="oga-spot__bio">${f.bio}</blockquote>`
        : ""}
      <div class="oga-spot__cta">${profileLink(f)}</div>
      <div class="oga-spot__timer">
        <div class="oga-spot__bar" aria-hidden="true"><span></span></div>
        <p class="oga-spot__next">
          Next face in <strong class="oga-spot__count">--:--</strong>
          ${d.tagline
            ? html`<span class="oga-spot__tag"> · ${d.tagline}</span>`
            : ""}
        </p>
      </div>
    </div>
  </article>`;
};

const recentHtml = (d) =>
  d.recent?.length
    ? html`<div class="oga-spot__recent">
        <h3 class="oga-spot__recent-title">Earlier in the spotlight</h3>
        <ul class="oga-spot__recent-list">
          ${d.recent.map(
            (r) =>
              html`<li>
                ${photoOf(r, false)
                  ? html`<img
                      src="${photoOf(r, false)}"
                      alt=""
                      loading="lazy"
                    />`
                  : html`<span class="oga-spot__mini" aria-hidden="true"
                      >${initials(r.name)}</span
                    >`}
                <strong>${r.name}</strong>
                <small>${r.line}</small>
              </li>`,
          )}
        </ul>
      </div>`
    : "";

class OgaSpotlight extends HTMLElement {
  #timer = null;
  #tick = null;
  #shuffle = null;
  #data = null;
  #skew = 0;
  #key = "";
  #onVisible = () => {
    if (
      !document.hidden &&
      this.#data &&
      Date.now() + this.#skew >= new Date(this.#data.endsAt).getTime() - 500
    )
      this.#load();
  };

  connectedCallback() {
    this.style.display = "block";
    this.#load();
    document.addEventListener("visibilitychange", this.#onVisible);
  }

  disconnectedCallback() {
    clearTimeout(this.#timer);
    clearInterval(this.#tick);
    clearInterval(this.#shuffle);
    document.removeEventListener("visibilitychange", this.#onVisible);
  }

  get #variant() {
    return this.getAttribute("variant") === "home" ? "home" : "page";
  }

  async #load() {
    clearTimeout(this.#timer);
    let d = null;
    try {
      const res = await fetch(`${API()}/spotlight/now`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });
      d = res.ok ? await res.json() : null;
    } catch {
      d = null;
    }
    if (!d?.enabled || !d.face) {
      this.#data = null;
      clearInterval(this.#tick);
      if (d?.enabled && this.#variant === "page") {
        mount(
          this,
          html`<p class="oga-spot__empty">
            <i class="fa-solid fa-star me-2"></i>The next face will appear here
            very soon.
          </p>`,
        );
        this.#schedule(30000);
      } else {
        this.replaceChildren();
        this.style.display = "none";
      }
      return;
    }
    this.style.display = "block";
    this.#skew = d.serverNow ? new Date(d.serverNow).getTime() - Date.now() : 0;
    const key = `${d.face.type}:${d.face.name}`;
    const changed = this.#data !== null && key !== this.#key;
    this.#data = d;
    this.#key = key;
    this.#draw(d, changed);
    const left = new Date(d.endsAt).getTime() - (Date.now() + this.#skew);
    // a little after the slot ends (and never everyone at the same instant); long slots re-check now and then
    this.#schedule(
      Math.min(Math.max(left, 0) + 400 + Math.random() * 1500, 3600000),
    );
  }

  #schedule(ms) {
    clearTimeout(this.#timer);
    this.#timer = setTimeout(() => this.#load(), ms);
  }

  #draw(d, changed) {
    const v = this.#variant;
    mount(
      this,
      html`<section class="oga-spot oga-spot--${v}" aria-live="polite">
        <header class="oga-spot__head">
          <span class="oga-spot__live"><i aria-hidden="true"></i>Live</span>
          <h2 class="oga-spot__heading">${d.heading}</h2>
          ${v === "page" && d.edition
            ? html`<p class="oga-spot__edition">${d.edition}</p>`
            : ""}
        </header>
        ${faceHtml(d, v)} ${v === "page" ? recentHtml(d) : ""}
      </section>`,
    );
    const card = this.querySelector(".oga-spot__card");
    this.#startClock(d);
    if (reduced()) return;
    if (changed && d.recent?.length > 1) this.#shuffleThen(card, d);
    else card.classList.add("is-reveal");
  }

  /** Earlier faces flash by, then the new face appears. (They are people who were already shown: nothing about who is next.) */
  #shuffleThen(card, d) {
    const img = card.querySelector(".oga-spot__photo img");
    const pics = d.recent.map((r) => photoOf(r, false)).filter(Boolean);
    const final = img?.getAttribute("src");
    if (!img || pics.length < 2) {
      card.classList.add("is-reveal");
      return;
    }
    card.classList.add("is-shuffling");
    let i = 0;
    clearInterval(this.#shuffle);
    this.#shuffle = setInterval(() => {
      img.src = pics[i++ % pics.length];
    }, 110);
    setTimeout(() => {
      clearInterval(this.#shuffle);
      img.src = final;
      card.classList.remove("is-shuffling");
      card.classList.add("is-reveal");
    }, 1300);
  }

  #startClock(d) {
    clearInterval(this.#tick);
    const start = new Date(d.startsAt).getTime();
    const end = new Date(d.endsAt).getTime();
    const paint = () => {
      const now = Date.now() + this.#skew;
      const count = this.querySelector(".oga-spot__count");
      const bar = this.querySelector(".oga-spot__bar span");
      if (count) count.textContent = clock(end - now);
      if (bar && end > start)
        bar.style.width = `${Math.min(100, Math.max(0, ((now - start) / (end - start)) * 100)).toFixed(1)}%`;
    };
    paint();
    this.#tick = setInterval(paint, 1000);
  }
}
if (!customElements.get("oga-spotlight"))
  customElements.define("oga-spotlight", OgaSpotlight);
