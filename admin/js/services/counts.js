import { store } from "../../../shared/js/core/store.js";

/**
 * Sidebar badge counts. The server works them out (they are sent with the sign-in data and refreshed
 * whenever the session is refreshed), so they are real and only include what this person may see.
 */
const count = (name) =>
  store.get("admin:badges", {})?.[store.get("admin:viewing")]?.[name] ?? 0;

export const unreadMessageCount = () => count("unreadMessages");
export const newApplicationCount = () => count("newApplications");
