/** Safe localStorage wrapper + tiny helpers. Used by the demo (mock) services until the backend exists. */
const PREFIX = "oga:";

export const store = {
  get(key, fallback = null) {
    try {
      const v = localStorage.getItem(PREFIX + key);
      return v === null ? fallback : JSON.parse(v);
    } catch {
      return fallback;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(PREFIX + key, JSON.stringify(value));
      return true;
    } catch {
      return false;
    }
  },
  remove(key) {
    try {
      localStorage.removeItem(PREFIX + key);
    } catch {
      /* ignore */
    }
  },
};

export const delay = (ms = 500) =>
  new Promise((resolve) => setTimeout(resolve, ms));

/** Short readable id, e.g. uid('TKT-') -> TKT-7K3QX9 (no look-alike characters). */
export function uid(prefix = "", length = 6) {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  const bytes = crypto.getRandomValues(new Uint8Array(length));
  return prefix + [...bytes].map((b) => chars[b % chars.length]).join("");
}

/** Error carrying a message that is safe to show to the user (and optional per-field errors). */
export const userError = (message, fieldErrors) =>
  Object.assign(new Error(message), { userMessage: message, fieldErrors });
