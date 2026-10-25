import { ROOT } from "../config/app.config.js";

/** Uploaded photos are saved as "uploads/…" paths on the server; this makes them load from any page. */
export const mediaUrl = (p) =>
  !p
    ? ""
    : /^(https?:)?\/\/|^data:/.test(p)
      ? p
      : ROOT + String(p).replace(/^\//, "");
