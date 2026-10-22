import { html, mount } from "../../../shared/js/core/dom.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";

function build(titleText, bodyHtml, { size = "" } = {}) {
  const el = document.createElement("div");
  el.className = "modal fade";
  el.tabIndex = -1;
  el.setAttribute("aria-hidden", "true");
  mount(
    el,
    html`<div
      class="modal-dialog modal-dialog-centered modal-dialog-scrollable ${size}"
    >
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5">${titleText}</h2>
          <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Close"
          ></button>
        </div>
        ${bodyHtml}
      </div>
    </div>`,
  );
  document.body.append(el);
  const modal = new window.bootstrap.Modal(el);
  el.addEventListener("hidden.bs.modal", () => {
    modal.dispose();
    el.remove();
  });
  return { el, modal };
}

/** Read-only modal with a close button. */
export function openInfoModal({ title, body, size = "" }) {
  const { modal } = build(
    title,
    html`<div class="modal-body">${body}</div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
      </div>`,
    { size },
  );
  modal.show();
}

/**
 * Form modal. fields: field() configs, plus { type: 'switch' } for on/off toggles.
 * onSubmit(values) may throw (errors are shown); the modal closes when it resolves.
 */
export function openFormModal({
  title,
  fields,
  values = {},
  schema = {},
  submitLabel = "Save",
  size = "",
  onSubmit,
}) {
  const switches = fields.filter((f) => f.type === "switch").map((f) => f.name);
  const { el, modal } = build(
    title,
    html`<form novalidate>
      <div class="modal-body">
        ${fields.map((f) =>
          f.type === "switch"
            ? html`<div class="form-check form-switch mb-3">
                <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="f-${f.name}"
                  name="${f.name}"
                  ${values[f.name] ? "checked" : ""}
                />
                <label class="form-check-label" for="f-${f.name}"
                  >${f.label}</label
                >
              </div>`
            : field({ ...f, value: values[f.name] ?? "" }),
        )}
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-gold">${submitLabel}</button>
      </div>
    </form>`,
    { size },
  );
  const form = el.querySelector("form");
  bindForm(form, {
    schema,
    async onSubmit(v) {
      switches.forEach((n) => {
        v[n] = form.elements[n].checked;
      });
      await onSubmit(v);
      modal.hide();
    },
  });
  modal.show();
  el.addEventListener(
    "shown.bs.modal",
    () =>
      form.querySelector("input:not([type=checkbox]),select,textarea")?.focus(),
    { once: true },
  );
}

/**
 * Modal with a custom body. `body` is html containing form controls; onSubmit(form) may throw
 * (the error is shown); the modal closes when it resolves.
 */
export function openCustomModal({
  title,
  body,
  submitLabel = "Save",
  size = "modal-lg",
  onSubmit,
}) {
  const { el, modal } = build(
    title,
    html`<form novalidate>
      <div class="modal-body">${body}</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-gold">${submitLabel}</button>
      </div>
    </form>`,
    { size: `${size} modal-dialog-scrollable` },
  );
  const form = el.querySelector("form");
  bindForm(form, {
    schema: {},
    async onSubmit() {
      await onSubmit(form);
      modal.hide();
    },
  });
  modal.show();
}
