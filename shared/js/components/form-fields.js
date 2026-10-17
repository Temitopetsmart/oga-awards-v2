import { html } from "../core/dom.js";

/**
 * Form field renderer — one consistent look for every form (public site + admin).
 * options: [{ value, label }] renders a <select>; type 'textarea' renders a textarea.
 */
export const field = ({
  label,
  name,
  type = "text",
  required = false,
  placeholder = "",
  help = "",
  autocomplete = "off",
  options = null,
  rows = 4,
  value = "",
  col = "",
  attrs = "",
}) =>
  html` <div class="oga-field mb-3 ${col}">
    <label class="form-label" for="f-${name}"
      >${label}${required
        ? html` <span class="text-gold" aria-hidden="true">*</span>`
        : ""}</label
    >
    ${options
      ? html`<select
          class="form-select"
          id="f-${name}"
          name="${name}"
          ${required ? "required" : ""}
        >
          <option value="">${placeholder || "Select…"}</option>
          ${options.map(
            (o) =>
              html`<option
                value="${o.value}"
                ${String(o.value) === String(value) ? "selected" : ""}
              >
                ${o.label}
              </option>`,
          )}
        </select>`
      : type === "textarea"
        ? html`<textarea
            class="form-control"
            id="f-${name}"
            name="${name}"
            rows="${rows}"
            placeholder="${placeholder}"
            ${required ? "required" : ""}
          >
${value}</textarea
          >`
        : html`<input
            class="form-control"
            id="f-${name}"
            name="${name}"
            type="${type}"
            value="${value}"
            placeholder="${placeholder}"
            autocomplete="${autocomplete}"
            ${required ? "required" : ""}
            ${attrs}
          />`}
    ${help ? html`<div class="form-text">${help}</div>` : ""}
  </div>`;

export const checkField = ({ name, label }) =>
  html` <div class="oga-field mb-3">
    <div class="form-check">
      <input
        class="form-check-input"
        type="checkbox"
        id="f-${name}"
        name="${name}"
        value="yes"
      />
      <label class="form-check-label" for="f-${name}">${label}</label>
    </div>
  </div>`;
