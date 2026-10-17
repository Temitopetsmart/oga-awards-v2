import { toHTML } from "../core/dom.js";

/**
 * <oga-countdown target="2026-12-01T00:00:00+01:00" label="Voting closes"></oga-countdown>
 * Fires a bubbling "countdown:end" event when it reaches zero.
 */
class OgaCountdown extends HTMLElement {
  static observedAttributes = ["target"];

  connectedCallback() {
    this.setAttribute("role", "timer");
    this.innerHTML = `<div class="oga-cd">${[
      "days",
      "hours",
      "minutes",
      "seconds",
    ]
      .map(
        (u) =>
          `<div class="oga-cd__unit"><span class="oga-cd__num" data-unit="${u}">00</span><span class="oga-cd__lbl">${u}</span></div>`,
      )
      .join("")}</div>`;
    this.#start();
  }

  disconnectedCallback() {
    clearInterval(this.timer);
  }
  attributeChangedCallback() {
    if (this.isConnected && this.querySelector(".oga-cd")) this.#start();
  }

  #start() {
    clearInterval(this.timer);
    this.#tick();
    this.timer = setInterval(() => this.#tick(), 1000);
  }

  #tick() {
    const target = new Date(this.getAttribute("target")).getTime();
    if (Number.isNaN(target)) return;
    const diff = Math.max(0, target - Date.now());
    const parts = {
      days: Math.floor(diff / 864e5),
      hours: Math.floor(diff / 36e5) % 24,
      minutes: Math.floor(diff / 6e4) % 60,
      seconds: Math.floor(diff / 1e3) % 60,
    };
    for (const [unit, value] of Object.entries(parts)) {
      const el = this.querySelector(`[data-unit="${unit}"]`);
      if (el) el.textContent = String(value).padStart(2, "0");
    }
    this.setAttribute(
      "aria-label",
      `${this.getAttribute("label") ?? "Time remaining"}: ${parts.days} days, ${parts.hours} hours, ${parts.minutes} minutes`,
    );
    if (diff === 0) {
      clearInterval(this.timer);
      this.dispatchEvent(new CustomEvent("countdown:end", { bubbles: true }));
    }
  }
}

if (!customElements.get("oga-countdown"))
  customElements.define("oga-countdown", OgaCountdown);
