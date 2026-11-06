/**
 * Matric number rules, mirroring backend/src/Matric.php so forms can check a number before sending it.
 * The server always re-checks. Pattern language:  # = digit,  @ = letter,  anything else = itself.
 * A token may be followed by {n} or {n,m}.  Example: @{3,5}/#{2}/#{4}
 */
export function compileMatric(pattern) {
  let re = "";
  let min = 0;
  let max = 0;
  const p = String(pattern ?? "");
  if (!p || p.length > 80) throw new Error("A pattern must be 1 to 80 characters.");
  for (let i = 0; i < p.length; i++) {
    const ch = p[i];
    if (ch === "{" || ch === "}")
      throw new Error("A {count} must directly follow # or @.");
    const cls = ch === "#" ? "[0-9]" : ch === "@" ? "[A-Z]" : null;
    let lo = 1;
    let hi = 1;
    if (cls && p[i + 1] === "{") {
      const m = /^\{(\d{1,2})(?:,(\d{1,2}))?\}/.exec(p.slice(i + 1));
      if (!m) throw new Error("Use {n} or {n,m} after # or @.");
      lo = Number(m[1]);
      hi = m[2] !== undefined ? Number(m[2]) : lo;
      if (lo < 1 || hi < lo || hi > 20) throw new Error("That {count} is not valid.");
      i += m[0].length;
    }
    if (!cls) {
      if (!/^[A-Za-z0-9/\-._]$/.test(ch))
        throw new Error("Pattern characters can be letters, digits, / - . _ and the tokens # @.");
      re += ch.toUpperCase().replace(/[.\-/]/g, "\\$&");
    } else {
      re += cls + (lo === hi ? (lo === 1 ? "" : `{${lo}}`) : `{${lo},${hi}}`);
    }
    min += lo;
    max += hi;
  }
  return { re: new RegExp(`^${re}$`), min, max };
}

/** The cleaned (upper-case, no spaces) number, or null if it matches none of the configured formats. */
export function normalizeMatric(value, config) {
  const v = String(value ?? "").toUpperCase().replace(/\s+/g, "");
  if (!v || !config?.formats?.length || v.length > Number(config.maxLength)) return null;
  for (const f of config.formats) {
    try {
      if (compileMatric(f.pattern).re.test(v)) return v;
    } catch {
      /* skip a broken pattern */
    }
  }
  return null;
}