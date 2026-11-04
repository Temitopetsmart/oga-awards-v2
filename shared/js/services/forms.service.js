import { userError } from "../core/store.js";
import { APP } from "../config/app.config.js";

/** The public contact form, sent to the PHP backend. The server validates it again and limits how often one connection can send. */
export async function sendContactMessage(data) {
  let res;
  try {
    res = await fetch(`${APP.data.apiBase}/contact`, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      credentials: "same-origin",
      body: JSON.stringify(data),
    });
  } catch {
    throw userError("Could not reach the server. Check your connection.");
  }
  let json = null;
  try {
    json = await res.json();
  } catch {
    /* not JSON */
  }
  if (!res.ok || json?.ok === false)
    throw userError(json?.error ?? "Something went wrong. Please try again.");
  return json;
}
