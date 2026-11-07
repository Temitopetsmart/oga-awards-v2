import { html } from "../../../shared/js/core/dom.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { statCard } from "../components/stat-card.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import { listNominees } from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import { fetchAllPages } from "../services/paging.js";
import {
  fetchVotesPage,
  addManualVotes,
  voidOrder,
  recheckOrder,
} from "../services/vote.admin.api.js";

export const title = "Votes";
export const perm = "votes.view";
export const crumbs = ["Awards", "Votes"];

export default async function votes({ can }) {
  return html` <div class="row g-3 mb-4" id="voteStats"></div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Vote ledger</h2>
        <div class="d-flex gap-2">
          ${can("votes.export")
            ? html`<button class="btn btn-ghost btn-sm" id="exportBtn">
                <i class="fa-solid fa-file-csv me-1"></i>Export CSV
              </button>`
            : ""}
          ${can("votes.addManual")
            ? html`<button class="btn btn-gold btn-sm" id="manualBtn">
                <i class="fa-solid fa-plus me-1"></i>Add manual votes
              </button>`
            : ""}
        </div>
      </div>
      <div id="voteTable"></div>
    </div>`;
}

export async function init(root, { admin, edition, can }) {
  const money$ = can("finance.view");
  const cats = await listCategories();
  const catOf = (id) => cats.find((c) => c.id === id)?.name ?? "—";
  let nominees = null; // only needed for the "Add manual votes" form, so loaded when it opens
  const shape = (o) => ({
    ...o,
    id: o.reference,
    nomineeName: o.nomineeName ?? "(removed)",
    categoryName: o.categoryName ?? "—",
    source: o.source ?? "public",
    voter: o.name || "—",
    at: o.createdAt ?? "",
  });

  const stats = (s) => {
    const host = root.querySelector("#voteStats");
    host.innerHTML = "";
    [
      statCard({
        icon: "fa-check-to-slot",
        label: "Paid votes",
        value: number(s.paidVotes),
        sub: money$ ? `${money(s.amount)} collected` : "",
      }),
      statCard({
        icon: "fa-hand",
        label: "Manual votes",
        value: number(s.manualVotes),
        sub: "Added by admins",
      }),
      statCard({
        icon: "fa-hourglass-half",
        label: "Pending payments",
        value: number(s.pending),
      }),
      statCard({
        icon: "fa-ban",
        label: "Void orders",
        value: number(s.void),
      }),
    ].forEach((c) => {
      const col = document.createElement("div");
      col.className = "col-sm-6 col-xl-3";
      col.innerHTML = c.__raw;
      host.append(col);
    });
  };

  const columns = [
    { key: "reference", label: "Reference", sort: true, class: "text-nowrap" },
    { key: "nomineeName", label: "Nominee" },
    { key: "categoryName", label: "Category" },
    { key: "votes", label: "Votes", sort: true },
    ...(money$
      ? [
          {
            key: "amount",
            label: "Amount",
            sort: true,
            render: (o) => (o.amount ? money(o.amount) : "—"),
          },
        ]
      : []),
    {
      key: "voter",
      label: "Voter",
      render: (o) =>
        html`${o.voter}${o.email
          ? html`<small class="d-block text-secondary">${o.email}</small>`
          : ""}`,
    },
    {
      key: "source",
      label: "Source",
      sort: true,
      render: (o) =>
        statusBadge(
          o.source === "manual" ? "unread" : "read",
          o.source === "manual" ? "Manual" : "Public",
        ),
    },
    {
      key: "status",
      label: "Status",
      sort: true,
      render: (o) => statusBadge(o.status),
    },
    {
      key: "at",
      label: "Date",
      sort: true,
      render: (o) =>
        o.at
          ? formatDate(o.at, { dateStyle: "medium", timeStyle: "short" })
          : "—",
    },
  ];

  let wantStats = true; // the totals are fetched with the first page and again after a change, not on every page turn
  const table = new DataTable(root.querySelector("#voteTable"), {
    can,
    server: async (p) => {
      const r = await fetchVotesPage(p, { stats: wantStats });
      if (r.stats) {
        wantStats = false;
        stats(r.stats);
      }
      return { rows: r.rows.map(shape), total: r.total, page: r.page };
    },
    filters: [
      {
        key: "status",
        label: "Status",
        options: [
          { value: "paid", label: "Paid" },
          { value: "pending", label: "Pending" },
          { value: "void", label: "Void" },
        ],
      },
      {
        key: "source",
        label: "Source",
        options: [
          { value: "public", label: "Public" },
          { value: "manual", label: "Manual" },
        ],
      },
      {
        key: "categoryId",
        label: "Category",
        options: cats.map((c) => ({ value: c.id, label: c.name })),
      },
    ],
    columns,
    actions: [
      {
        key: "view",
        label: "View details",
        icon: "fa-eye",
        perm: "votes.view",
      },
      {
        key: "paid",
        label: "Mark as paid",
        icon: "fa-check",
        variant: "gold",
        perm: "finance.reconcile",
        when: (o) => o.status === "pending",
      },
      {
        key: "void",
        label: "Void order",
        icon: "fa-ban",
        perm: "votes.void",
        when: (o) => o.status !== "void",
      },
    ],
    empty: "No votes yet in this edition.",
    onAction: (key, o) => handlers[key](o),
  });
  const refresh = async () => {
    wantStats = true;
    await table.reload();
  };

  const handlers = {
    view: (o) =>
      openInfoModal({
        title: o.reference,
        body: html`<dl class="oga-dl">
          <div>
            <dt>Status</dt>
            <dd>${statusBadge(o.status)}</dd>
          </div>
          <div>
            <dt>Nominee</dt>
            <dd>${o.nomineeName}</dd>
          </div>
          <div>
            <dt>Category</dt>
            <dd>${o.categoryName}</dd>
          </div>
          <div>
            <dt>Votes</dt>
            <dd>${o.votes}</dd>
          </div>
          ${money$
            ? html`<div>
                <dt>Amount</dt>
                <dd>${o.amount ? money(o.amount) : "—"}</dd>
              </div>`
            : ""}
          <div>
            <dt>Voter</dt>
            <dd>${o.voter}${o.email ? ` · ${o.email}` : ""}</dd>
          </div>
          <div>
            <dt>Source</dt>
            <dd>${o.source === "manual" ? "Manual (admin)" : "Public site"}</dd>
          </div>
          ${o.reason
            ? html`<div>
                <dt>Reason</dt>
                <dd>${o.reason}</dd>
              </div>`
            : ""}
        </dl>`,
      }),
    async paid(o) {
      if (
        !(await notify.confirm({
          title: `Re-check ${o.reference} with the payment provider?`,
          text: "We ask the provider again. The order is counted only if the provider confirms a successful payment.",
          confirmText: "Re-check payment",
        }))
      )
        return;
      try {
        await recheckOrder(o.reference);
      } catch (err) {
        await notify.warning("Not paid yet", err.message);
        return;
      }
      notify.toast("success", "Payment confirmed and counted");
      await refresh();
    },
    async void(o) {
      if (
        !(await notify.confirm({
          title: `Void ${o.reference}?`,
          text: "Its votes will no longer count.",
          confirmText: "Void order",
          danger: true,
        }))
      )
        return;
      try {
        await voidOrder(o.reference);
      } catch (err) {
        await notify.warning("Couldn’t void the order", err.message);
        return;
      }
      notify.toast("info", "Order voided");
      await refresh();
    },
  };

  root.querySelector("#exportBtn")?.addEventListener("click", async () => {
    // Everything that matches the current search and filters, not just this page.
    const { q, sort, filters } = table.state;
    const rows = (
      await fetchAllPages(({ page, perPage }) =>
        fetchVotesPage({
          q,
          sort: sort?.key,
          dir: sort?.dir,
          filters,
          page,
          perPage,
        }),
      )
    ).map(shape);
    downloadCsv("oga-votes.csv", rows, [
      { label: "Reference", key: "reference" },
      { label: "Nominee", key: "nomineeName" },
      { label: "Category", key: "categoryName" },
      { label: "Votes", key: "votes" },
      ...(money$ ? [{ label: "Amount", value: (o) => o.amount }] : []),
      { label: "Voter", value: (o) => o.voter },
      { label: "Email", value: (o) => o.email ?? "" },
      { label: "Status", key: "status" },
      { label: "Source", value: (o) => o.source },
      { label: "Date", value: (o) => o.at },
      { label: "Reason", value: (o) => o.reason ?? "" },
    ]);
  });

  root.querySelector("#manualBtn")?.addEventListener("click", async () => {
    nominees ??= await listNominees();
    openFormModal({
      title: "Add manual votes",
      submitLabel: "Add votes",
      fields: [
        {
          name: "nomineeId",
          label: "Nominee",
          required: true,
          options: nominees
            .filter((n) => n.status === "approved")
            .map((n) => ({
              value: n.id,
              label: `${n.name} — ${catOf(n.categoryId)}`,
            })),
        },
        {
          name: "votes",
          label: "Number of votes",
          type: "number",
          required: true,
          attrs: 'min="1" max="10000"',
        },
        {
          name: "reason",
          label: "Reason",
          type: "textarea",
          rows: 3,
          required: true,
          help: "Recorded in the audit log, e.g. “Cash votes collected at the campus booth”.",
        },
      ],
      schema: {
        nomineeId: [rules.required("Choose a nominee.")],
        votes: [
          rules.required(),
          rules.custom((v) =>
            Number.isInteger(Number(v)) && Number(v) >= 1 && Number(v) <= 10000
              ? null
              : "Enter a whole number from 1 to 10,000.",
          ),
        ],
        reason: [rules.required(), rules.min(8)],
      },
      async onSubmit(v) {
        await addManualVotes({
          nomineeId: v.nomineeId,
          votes: v.votes,
          reason: v.reason.trim(),
        });
        notify.toast("success", "Manual votes added");
        await refresh();
      },
    });
  });
}
