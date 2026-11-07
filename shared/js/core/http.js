import { APP } from "../config/app.config.js";

export class HttpError extends Error {
  constructor(message, status) {
    super(message);
    this.name = "HttpError";
    this.status = status;
  }
}

const cache = new Map();

/** Fetch a named resource from the PHP API. */
export function getResource(name) {
  if (cache.has(name)) return cache.get(name);

  const url = `${APP.data.apiBase}/${encodeURIComponent(name)}`;

  const request = fetch(url, {
    headers: { Accept: "application/json" },
  }).then(async (res) => {
    if (!res.ok) {
      throw new HttpError(
        `Could not load "${name}" (${res.status})`,
        res.status,
      );
    }

    return res.json();
  });

  cache.set(name, request);
  request.catch(() => cache.delete(name));

  return request;
}

/** Compatibility helper: the PHP API is now the source of truth. */
export async function getSeed(name) {
  return getResource(name);
}
