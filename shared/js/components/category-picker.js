import { html } from "../core/dom.js";
import { categoryLabel } from "./matric-field.js";

/** A tick-list of categories: a nominee can enter several in one edition (the admin sets how many). */
export function categoryPicker({
  categories,
  taken = [],
  max = 1,
  feeNote = false,
  col = "",
}) {
  const left = Math.max(0, max - taken.length);
  const help =
    max === 1
      ? "Choose one category."
      : `You can enter up to ${max} categories${taken.length ? ` (${left} left)` : ""}.` +
        (feeNote
          ? " The registration fee is paid once, not per category."
          : "");
  return html`<div class="oga-field mb-3 ${col}" data-category-picker>
    <label class="form-label"
      >${max === 1 ? "Category" : "Categories"}
      <span class="text-gold" aria-hidden="true">*</span></label
    >
    ${categories.map((c) => {
      const has = taken.includes(String(c.id));
      return html`<div class="form-check">
        <input
          class="form-check-input"
          type="checkbox"
          name="categoryIds"
          id="cat-${c.id}"
          value="${c.id}"
          data-levels="${(c.eligibleLevels ?? []).join("|")}"
          ${has ? "checked disabled" : ""}
        />
        <label class="form-check-label" for="cat-${c.id}"
          >${categoryLabel(c)}${has ? " — already entered" : ""}</label
        >
      </div>`;
    })}
    <div class="form-text" data-picker-note>${help}</div>
  </div>`;
}

/** The ids ticked now (locked "already entered" ones are not included). */
export const pickedCategories = (form) =>
  [
    ...form.querySelectorAll(
      'input[name="categoryIds"]:checked:not(:disabled)',
    ),
  ].map((i) => i.value);

/** Stops at the limit and switches off categories the chosen level may not enter. The server checks again. */
export function bindCategoryPicker(
  form,
  { max = 1, taken = [], level: fixedLevel = "" } = {},
) {
  const boxes = () => [...form.querySelectorAll('input[name="categoryIds"]')];
  const levelBox = form.elements.level;
  const sync = () => {
    const level = levelBox?.value ?? fixedLevel;
    const room = Math.max(0, max - taken.length);
    for (const box of boxes()) {
      if (taken.includes(box.value)) continue;
      const levels = box.dataset.levels ? box.dataset.levels.split("|") : [];
      const eligible = !level || levels.length === 0 || levels.includes(level);
      if (!eligible) box.checked = false;
      box.dataset.ineligible = eligible ? "" : "1";
    }
    const ticked = pickedCategories(form).length;
    for (const box of boxes()) {
      if (taken.includes(box.value)) continue;
      box.disabled =
        box.dataset.ineligible === "1" || (!box.checked && ticked >= room);
    }
  };
  form.addEventListener("change", (e) => {
    if (e.target.name === "categoryIds" || e.target.name === "level") sync();
  });
  sync();
}
