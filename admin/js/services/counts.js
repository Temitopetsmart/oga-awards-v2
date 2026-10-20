// admin/js/services/counts.js
import { store } from "../../../shared/js/core/store.js";
import { scoped } from "./scope.js";

/** Small counters for sidebar badges (read straight from the demo stores, in the edition being viewed). */
export const unreadMessageCount = () =>
  scoped(store.get("contact-messages", [])).filter((m) => !m.read).length;
export const newApplicationCount = () =>
  scoped(store.get("committee-applications", [])).filter(
    (a) => !a.status || a.status === "new",
  ).length;
