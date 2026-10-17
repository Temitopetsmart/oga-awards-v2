import { validate } from "../core/validate.js";
import { notify } from "./notify.js";

/**
 * Wire a <form> to a validation schema + async submit handler.
 * Shows inline errors, disables the button while working, and reports failures via notify.
 */
export function bindForm(form, { schema = {}, onSubmit }) {
  const button = form.querySelector('[type="submit"]');
  form.noValidate = true;

  const clearFeedback = () => {
    form.querySelectorAll(".is-invalid").forEach((el) => {
      el.classList.remove("is-invalid");
      el.removeAttribute("aria-invalid");
    });
    form
      .querySelectorAll(".invalid-feedback[data-auto]")
      .forEach((el) => el.remove());
  };

  const showErrors = (errors) => {
    let first = null;
    for (const [name, message] of Object.entries(errors)) {
      const el = form.elements[name];
      if (!el || !el.classList) continue;
      el.classList.add("is-invalid");
      el.setAttribute("aria-invalid", "true");
      const note = document.createElement("div");
      note.className = "invalid-feedback";
      note.dataset.auto = "";
      note.textContent = message;
      (el.closest(".oga-field") ?? el.parentElement).append(note);
      first ??= el;
    }
    first?.focus();
  };

  form.addEventListener("input", (e) => {
    const field = e.target.closest?.(".oga-field");
    e.target.classList?.remove("is-invalid");
    field?.querySelector(".invalid-feedback[data-auto]")?.remove();
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    clearFeedback();
    const values = Object.fromEntries(new FormData(form));
    const errors = validate(values, schema);
    if (Object.keys(errors).length) {
      showErrors(errors);
      return;
    }

    if (button) {
      button.dataset.label = button.innerHTML;
      button.disabled = true;
      button.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Please wait…';
    }
    try {
      await onSubmit(values, form);
    } catch (err) {
      if (err.cancelled) notify.toast("info", err.userMessage ?? "Cancelled.");
      else if (err.fieldErrors) showErrors(err.fieldErrors);
      else await notify.failure(err);
    } finally {
      if (button?.isConnected) {
        button.disabled = false;
        button.innerHTML = button.dataset.label;
      }
    }
  });
}
