/** Safe HTML templating + tiny DOM helpers. Interpolated values are escaped by default. */
const ESC = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
};
export const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ESC[c]);

/** Mark a string as trusted HTML (use only for markup you built yourself). */
export const raw = (s) => ({ __raw: String(s ?? "") });

function render(v) {
  if (v == null || v === false) return "";
  if (Array.isArray(v)) return v.map(render).join("");
  if (typeof v === "object" && "__raw" in v) return v.__raw;
  return esc(v);
}

/** Tagged template: html`<p>${userText}</p>` — values escaped, nested html`` kept. */
export function html(strings, ...vals) {
  let out = strings[0];
  vals.forEach((v, i) => {
    out += render(v) + strings[i + 1];
  });
  return raw(out);
}

export const toHTML = render;
export const mount = (el, content) => {
  el.innerHTML = render(content);
  return el;
};
export const $ = (sel, ctx = document) => ctx.querySelector(sel);
export const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

/** Allow only safe characters in a class string coming from data (e.g. icon names). */
export const safeClass = (s) => String(s ?? "").replace(/[^a-z0-9 _-]/gi, "");

export const initials = (name = "") =>
  name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "")
    .join("") || "?";
