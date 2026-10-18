import { store } from "../../../shared/js/core/store.js";

/** Small counters for sidebar badges (read straight from the demo stores). */
export const unreadMessageCount = () =>
  store.get("contact-messages", []).filter((m) => !m.read).length;
export const newApplicationCount = () =>
  store
    .get("committee-applications", [])
    .filter((a) => !a.status || a.status === "new").length;
