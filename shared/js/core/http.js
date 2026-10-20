import { APP, ROOT } from "../config/app.config.js";

export class HttpError extends Error {
  constructor(message, status) {
    super(message);
    this.name = "HttpError";
    this.status = status;
  }
}

const cache = new Map();

/**
 * DEMO BRIDGE: while data comes from JSON files, anything saved from the admin (kept in this
 * browser under oga:admin:<name>) overrides the file, so admin changes show on the public site.
 * Delete this and DEMO_OVERRIDES when the PHP API replaces the JSON files.
 */
const DEMO_OVERRIDES = [
  "editions",
  "categories",
  "nominees",
  "ticket-types",
  "winners",
];

function demoOverride(name, data) {
  if (!DEMO_OVERRIDES.includes(name)) return data;
  try {
    const raw = localStorage.getItem(`oga:admin:${name}`);
    return raw ? JSON.parse(raw) : data;
  } catch {
    return data;
  }
}

/** Fetch a named resource (e.g. 'categories'). JSON files today, PHP API later. */
export function getResource(name) {
  if (cache.has(name)) return cache.get(name);
  const url =
    APP.data.source === "json"
      ? `${ROOT}${APP.data.jsonPath}${name}.json`
      : `${APP.data.apiBase}/${name}`;
  const request = fetch(url, { headers: { Accept: "application/json" } })
    .then((res) => {
      if (!res.ok)
        throw new HttpError(
          `Could not load "${name}" (${res.status})`,
          res.status,
        );
      return res.json();
    })
    .then((data) =>
      APP.data.source === "json" ? demoOverride(name, data) : data,
    );
  cache.set(name, request);
  request.catch(() => cache.delete(name)); // don't cache failures
  return request;
}

/** Raw file contents, ignoring admin overrides (used to seed the admin's own store). */
export async function getSeed(name) {
  const res = await fetch(`${ROOT}${APP.data.jsonPath}${name}.json`, {
    headers: { Accept: "application/json" },
  });
  if (!res.ok)
    throw new HttpError(`Could not load "${name}" (${res.status})`, res.status);
  return res.json();
}
