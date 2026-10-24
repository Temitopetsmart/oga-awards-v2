import { APP } from "../../../shared/js/config/app.config.js";
import { userError } from "../../../shared/js/core/store.js";

/**
 * The one way the admin talks to the PHP backend. It sends the session cookie, adds the
 * CSRF token to every change, and turns failures into a friendly message.
 */
let csrf = null;
export const setCsrf = (token) => {
  csrf = token;
};

async function send(path, method, body, extra = {}) {
  const headers = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (method !== "GET" && csrf) headers["X-CSRF-Token"] = csrf;
  const res = await fetch(`${APP.data.apiBase}${path}`, {
    method,
    headers,
    credentials: "same-origin",
    body: body === undefined ? undefined : JSON.stringify(body),
    ...extra,
  });
  let data = null;
  try {
    data = await res.json();
  } catch {
    /* the reply was not JSON */
  }
  return { res, data };
}

async function refreshCsrf() {
  const { data } = await send("/admin/csrf", "GET");
  setCsrf(data?.csrf ?? null);
}

/** Call an /admin endpoint. Returns the JSON, or throws an error that is safe to show the person. */
export async function adminApi(
  path,
  { method = "GET", body, keepalive = false } = {},
) {
  if (method !== "GET" && !csrf) await refreshCsrf();
  let { res, data } = await send(path, method, body, { keepalive });
  if (res.status === 419) {
    await refreshCsrf(); // the token was out of date: get a new one and try once more
    ({ res, data } = await send(path, method, body, { keepalive }));
  }
  if (data?.csrf) setCsrf(data.csrf);
  if (!res.ok)
    throw userError(data?.error ?? "Something went wrong. Please try again.");
  return data;
}
