import { APP, ROOT } from "../config/app.config.js";

export class HttpError extends Error {
  constructor(message, status) {
    super(message);
    this.name = "HttpError";
    this.status = status;
  }
}

const cache = new Map();

/** Fetch a named resource (e.g. 'categories'). JSON files today, PHP API later. */
export function getResource(name) {
  if (cache.has(name)) return cache.get(name);
  const url =
    APP.data.source === "json"
      ? `${ROOT}${APP.data.jsonPath}${name}.json`
      : `${APP.data.apiBase}/${name}`;
  const request = fetch(url, { headers: { Accept: "application/json" } }).then(
    (res) => {
      if (!res.ok)
        throw new HttpError(
          `Could not load "${name}" (${res.status})`,
          res.status,
        );
      return res.json();
    },
  );
  cache.set(name, request);
  request.catch(() => cache.delete(name)); // don't cache failures
  return request;
}
