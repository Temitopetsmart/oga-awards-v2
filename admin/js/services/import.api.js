import { adminApi } from "./admin-api.js";

/** Phase 19. `dryRun: true` only checks the file and reports what it would create. */
export const importEdition = (edition, payload, dryRun) =>
  adminApi("/admin/import/edition", {
    method: "POST",
    body: { edition, payload, dryRun },
  });
