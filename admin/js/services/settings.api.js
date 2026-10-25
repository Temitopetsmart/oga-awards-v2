import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";

/** Site-wide settings kept on the server: contact, social, communications, gateway. */
const viewing = () => store.get("admin:viewing");

/** Returns { contact, social, communications, gateway } — a section is missing until it has been saved once. */
export async function fetchSettings(editionId = viewing()) {
  try {
    const r = await adminApi(
      `/admin/settings?edition=${encodeURIComponent(editionId)}`,
    );
    return Object.fromEntries(
      Object.entries(r.settings).filter(([, v]) => v !== null),
    );
  } catch {
    return {};
  }
}

/** section: "contact" | "social" | "communications" | "gateway" */
export const saveSettings = (section, value) =>
  adminApi(`/admin/settings/${section}`, {
    method: "PUT",
    body: { edition: viewing(), value },
  });
