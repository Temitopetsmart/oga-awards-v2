/** Helpers for the server-paged admin lists. */

/** "a=1&b=2" from the non-empty values of an object (the DataTable's server request). */
export function pageQuery(
  { q = "", page = 1, perPage = 25, sort = "", dir = "", filters = {} } = {},
  extra = {},
) {
  const p = new URLSearchParams();
  const all = { page, perPage, q, sort, dir, ...filters, ...extra };
  for (const [k, v] of Object.entries(all)) {
    if (v !== "" && v !== null && v !== undefined) p.set(k, String(v));
  }
  return p.toString();
}

/**
 * Every row of a list, fetched a page at a time (for CSV exports). `loadPage({page, perPage})` must resolve to
 * `{ rows, pages }`. Stops at `max` rows so a huge list cannot freeze the browser.
 */
export async function fetchAllPages(
  loadPage,
  { perPage = 500, max = 50000 } = {},
) {
  const rows = [];
  for (let page = 1; ; page++) {
    const r = await loadPage({ page, perPage });
    rows.push(...r.rows);
    if (page >= r.pages || rows.length >= max) break;
  }
  return rows;
}
