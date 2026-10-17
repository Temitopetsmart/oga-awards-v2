import { APP, ROOT } from "../config/app.config.js";

/** Keeps ?preview=… alive while navigating (dev only; no effect when allowPreview is false). */
export const previewKey = () =>
  APP.allowPreview ? new URLSearchParams(location.search).get("preview") : null;

export function withPreview(url) {
  const key = previewKey();
  if (!key) return url;
  const u = new URL(url, location.href);
  if (u.origin === location.origin && !u.searchParams.has("preview"))
    u.searchParams.set("preview", key);
  return u.href;
}

/** Navigate to a project page, e.g. go('ticket.html?code=ABC'). */
export const go = (path) => location.assign(withPreview(ROOT + path));
