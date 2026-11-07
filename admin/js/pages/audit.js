import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { DataTable } from "../components/data-table.js";
import { fetchAuditPage, recordAuditExport } from "../services/audit.api.js";
import { fetchAllPages } from "../services/paging.js";
import { listEditions, editionLabel } from "../services/edition.repo.js";

export const title = "Audit log";
export const perm = "audit.view";
export const crumbs = ["Administration", "Audit log"];

export default async function audit() {
  return html`<div class="card">
    <div
      class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
    >
      <h2 class="card-title mb-0">Activity history</h2>
      <button class="btn btn-ghost btn-sm" id="exportBtn">
        <i class="fa-solid fa-file-csv me-1"></i>Export CSV
      </button>
    </div>
    <div id="auditTable"></div>
  </div>`;
}

export async function init(root, { admin }) {
  const editions = listEditions();
  const label = (id) => {
    const e = editions.find((x) => x.id === id);
    return e ? editionLabel(e) : "All editions";
  };
  const shape = (r) => ({ ...r, editionName: label(r.editionId) });
  // The people who can sign in, for the "Admin" filter (one small request).
  const admins =
    (await fetchAuditPage({ perPage: 5 }, { meta: true })).admins ?? [];

  const table = new DataTable(root.querySelector("#auditTable"), {
    pageSize: 25,
    server: async (p) => {
      const r = await fetchAuditPage(p);
      return { rows: r.rows.map(shape), total: r.total, page: r.page };
    },
    filters: [
      {
        key: "admin",
        label: "Admin",
        options: admins.map((a) => ({ value: a.id, label: a.name })),
      },
      {
        key: "editionKey",
        label: "Edition",
        options: [
          ...editions.map((e) => ({ value: e.id, label: editionLabel(e) })),
          { value: "none", label: "Not edition-specific" },
        ],
      },
    ],
    columns: [
      {
        key: "at",
        label: "When",
        sort: true,
        class: "text-nowrap",
        render: (r) =>
          formatDate(r.at, { dateStyle: "medium", timeStyle: "short" }),
      },
      { key: "admin", label: "Admin", sort: true },
      { key: "action", label: "Action", sort: true },
      { key: "target", label: "Details" },
      { key: "editionName", label: "Edition", sort: true },
    ],
    empty: "Nothing recorded yet.",
  });

  // The CSV holds everything that matches the current search and filters, not just this page.
  root.querySelector("#exportBtn").addEventListener("click", async () => {
    const { q, sort, filters } = table.state;
    const rows = (
      await fetchAllPages(({ page, perPage }) =>
        fetchAuditPage({
          q,
          sort: sort?.key,
          dir: sort?.dir,
          filters,
          page,
          perPage,
        }),
      )
    ).map(shape);
    downloadCsv("oga-audit-log.csv", rows, [
      { label: "When", key: "at" },
      { label: "Admin", key: "admin" },
      { label: "Action", key: "action" },
      { label: "Details", key: "target" },
      { label: "Edition", key: "editionName" },
    ]);
    recordAuditExport();
  });
}
