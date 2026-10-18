import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { DataTable } from "../components/data-table.js";
import { getAudit, logAudit } from "../services/admin-store.js";

export const title = "Audit log";
export const perm = "audit";
export const crumbs = ["System", "Audit log"];

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
  const rows = getAudit();
  const admins = [...new Set(rows.map((r) => r.admin))].sort();
  new DataTable(root.querySelector("#auditTable"), {
    rows,
    pageSize: 15,
    searchKeys: ["admin", "action", "target"],
    filters: [
      {
        key: "admin",
        label: "Admin",
        options: admins.map((a) => ({ value: a, label: a })),
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
    ],
    empty: "Nothing recorded yet.",
  });
  root.querySelector("#exportBtn").addEventListener("click", () => {
    downloadCsv("oga-audit-log.csv", rows, [
      { label: "When", key: "at" },
      { label: "Admin", key: "admin" },
      { label: "Action", key: "action" },
      { label: "Details", key: "target" },
    ]);
    logAudit(admin, "Exported audit log");
  });
}
