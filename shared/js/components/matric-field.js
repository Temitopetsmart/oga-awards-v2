import { field } from "./form-fields.js";
import { APP } from "../config/app.config.js";
import { normalizeMatric } from "../core/matric.js";
import { rules } from "../core/validate.js";

/** Does a student at this level have to give a matric number? (Staff do not; the admin decides in Settings.) */
export const levelNeedsMatric = (level) =>
  APP.levelRules.find((l) => l.name === level)?.requiresMatric ?? true;

const examples = () =>
  (APP.matric?.formats ?? []).map((f) => f.example).filter(Boolean);

/** The matric number input, with the admin's format shown as an example. */
export function matricField({
  col = "col-md-6",
  value = "",
  readonly = false,
} = {}) {
  const ex = examples();
  return field({
    label: "Matric number",
    name: "matricNo",
    required: true,
    col,
    value,
    placeholder: ex[0] ?? "",
    help: ex.length ? `For example ${ex.join(" or ")}.` : "",
    attrs: `maxlength="${APP.matric?.maxLength ?? 13}" style="text-transform:uppercase" ${readonly ? "readonly" : ""}`,
  });
}

/** Validation: required when the chosen level asks for one, and must match the admin's format whenever it is given. */
export const matricRule = rules.custom((v, all) => {
  const text = String(v ?? "").trim();
  if (!text)
    return levelNeedsMatric(all.level) ? "Enter your matric number." : null;
  if (normalizeMatric(text, APP.matric)) return null;
  const ex = examples();
  return `That is not a valid matric number${ex.length ? ` (for example ${ex.join(" or ")})` : ""}.`;
});

/** Hides the matric box for levels that have none (staff), shows it again if the level changes back. */
export function bindMatricVisibility(form) {
  const level = form.elements.level;
  const box = form.elements.matricNo?.closest(".oga-field");
  if (!level || !box) return;
  const sync = () =>
    box.classList.toggle(
      "d-none",
      !levelNeedsMatric(level.value) && !form.elements.matricNo.value,
    );
  level.addEventListener("change", sync);
  sync();
}

/** "Best Lecturer (Staff only)" for categories that only some levels may enter. */
export const categoryLabel = (c) =>
  c.eligibleLevels?.length
    ? `${c.name} (${c.eligibleLevels.join(", ")} only)`
    : c.name;
