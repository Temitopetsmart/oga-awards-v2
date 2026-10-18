import { html, safeClass } from "../../../shared/js/core/dom.js";

/** Dashboard stat tile. Pass `href` to make it a link. */
export const statCard = ({ icon, label, value, sub = "", href = "" }) => {
  const inner = html` <span class="oga-icon"
      ><i class="fa-solid ${safeClass(icon)}"></i
    ></span>
    <span
      ><span class="oga-stat__value">${value}</span
      ><span class="oga-stat__label">${label}</span>${sub
        ? html`<span class="oga-stat__sub">${sub}</span>`
        : ""}</span
    >`;
  return href
    ? html`<a class="oga-stat" href="${href}">${inner}</a>`
    : html`<div class="oga-stat">${inner}</div>`;
};
