/** Small validation toolkit. A schema maps field name -> list of rules; first failing rule wins. */
export const rules = {
  required:
    (msg = "This field is required.") =>
    (v) =>
      String(v ?? "").trim() ? null : msg,
  email: () => (v) =>
    !v || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)
      ? null
      : "Enter a valid email address.",
  phone: () => (v) =>
    !v || /^\+?[0-9\s-]{10,15}$/.test(v) ? null : "Enter a valid phone number.",
  min: (n) => (v) =>
    !v || String(v).trim().length >= n ? null : `Use at least ${n} characters.`,
  max: (n) => (v) =>
    !v || String(v).trim().length <= n ? null : `Use at most ${n} characters.`,
  match:
    (other, msg = "Values do not match.") =>
    (v, all) =>
      v === all[other] ? null : msg,
  custom: (fn) => (v, all) => fn(v, all) ?? null,
};

export function validate(values, schema) {
  const errors = {};
  for (const [field, list] of Object.entries(schema)) {
    for (const rule of list) {
      const message = rule(values[field], values);
      if (message) {
        errors[field] = message;
        break;
      }
    }
  }
  return errors;
}
