import { html } from "../../../shared/js/core/dom.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store, uid } from "../../../shared/js/core/store.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { statCard } from "../components/stat-card.js";
import { openFormModal, openInfoModal } from "../components/modal.js";
import { listNominees } from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import { logAudit } from "../services/admin-store.js";

export const title = "Votes";
export const perm = "votes";
export const crumbs = ["Awards", "Votes"];
const KEY = "vote-orders";

export default async function votes() {
  return html` <div class="row g-3 mb-4" id="voteStats"></div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Vote ledger</h2>
        <div class="d-flex gap-2">
          <button class="btn btn-ghost btn-sm" id="exportBtn">
            <i class="fa-solid fa-file-csv me-1"></i>Export CSV
          </button>
          <button class="btn btn-gold btn-sm" id="manualBtn">
            <i class="fa-solid fa-plus me-1"></i>Add manual votes
          </button>
        </div>
      </div>
      <div id="voteTable"></div>
    </div>`;
}

export async function init(root, { admin }) {
  const [nominees, cats] = await Promise.all([
    listNominees(),
    listCategories(),
  ]);
  const nameOf = (id) => nominees.find((n) => n.id === id)?.name ?? "(removed)";
  const catOf = (id) => cats.find((c) => c.id === id)?.name ?? "—";
  const orders = () => store.get(KEY, []);
  const patch = (ref, data) =>
    store.set(
      KEY,
      orders().map((o) => (o.reference === ref ? { ...o, ...data } : o)),
    );
  const load = () =>
    orders()
      .map((o) => ({
        ...o,
        id: o.reference,
        nomineeName: nameOf(o.nomineeId),
        categoryName: catOf(o.categoryId),
        source: o.source ?? "public",
        voter: o.name || "—",
        at: o.createdAt ?? "",
      }))
      .sort((a, b) => String(b.at).localeCompare(String(a.at)));

  const stats = () => {
    const rows = orders();
    const paid = rows.filter((o) => o.status === "paid");
    const sum = (list) => list.reduce((s, o) => s + o.votes, 0);
    root.querySelector("#voteStats").innerHTML = "";
    const cards = [
      statCard({
        icon: "fa-check-to-slot",
        label: "Paid votes",
        value: number(sum(paid.filter((o) => o.source !== "manual"))),
        sub: `${money(paid.reduce((s, o) => s + o.amount, 0))} collected`,
      }),
      statCard({
        icon: "fa-hand",
        label: "Manual votes",
        value: number(sum(paid.filter((o) => o.source === "manual"))),
        sub: "Added by admins",
      }),
      statCard({
        icon: "fa-hourglass-half",
        label: "Pending payments",
        value: number(rows.filter((o) => o.status === "pending").length),
      }),
      statCard({
        icon: "fa-ban",
        label: "Void orders",
        value: number(rows.filter((o) => o.status === "void").length),
      }),
    ];
    cards.forEach((c) => {
      const col = document.createElement("div");
      col.className = "col-sm-6 col-xl-3";
      col.innerHTML = c.__raw;
      root.querySelector("#voteStats").append(col);
    });
  };

  const columns = [
    { key: "reference", label: "Reference", sort: true, class: "text-nowrap" },
    { key: "nomineeName", label: "Nominee", sort: true },
    { key: "categoryName", label: "Category", sort: true },
    { key: "votes", label: "Votes", sort: true },
    {
      key: "amount",
      label: "Amount",
      sort: true,
      render: (o) => (o.amount ? money(o.amount) : "—"),
    },
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

  const table = new DataTable(root.querySelector("#voteTable"), {
    rows: load(),
    searchKeys: ["reference", "nomineeName", "voter", "email", "categoryName"],
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
      { key: "view", label: "View details", icon: "fa-eye" },
      {
        key: "paid",
        label: "Mark as paid",
        icon: "fa-check",
        variant: "gold",
        when: (o) => o.status === "pending",
      },
      {
        key: "void",
        label: "Void order",
        icon: "fa-ban",
        when: (o) => o.status !== "void",
      },
    ],
    empty: "No votes yet. Votes cast on the public site appear here.",
    onAction: (key, o) => handlers[key](o),
  });
  const refresh = () => {
    table.setRows(load());
    stats();
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
          <div>
            <dt>Amount</dt>
            <dd>${o.amount ? money(o.amount) : "—"}</dd>
          </div>
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
          title: `Mark ${o.reference} as paid?`,
          text: "Only do this after confirming the payment with the payment provider.",
          confirmText: "Mark as paid",
        }))
      )
        return;
      patch(o.reference, { status: "paid" });
      logAudit(admin, "Marked vote order paid", o.reference);
      notify.toast("success", "Marked as paid");
      refresh();
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
      patch(o.reference, { status: "void" });
      logAudit(admin, "Voided vote order", o.reference);
      notify.toast("info", "Order voided");
      refresh();
    },
  };

  root.querySelector("#exportBtn").addEventListener("click", () => {
    downloadCsv("oga-votes.csv", load(), [
      ...columns.filter(
        (c) =>
          c.key !== "amount" &&
          c.key !== "voter" &&
          c.key !== "at" &&
          c.key !== "source",
      ),
      { label: "Amount", value: (o) => o.amount },
      { label: "Voter", value: (o) => o.voter },
      { label: "Email", value: (o) => o.email ?? "" },
      { label: "Source", value: (o) => o.source },
      { label: "Date", value: (o) => o.at },
      { label: "Reason", value: (o) => o.reason ?? "" },
    ]);
    logAudit(admin, "Exported vote ledger");
  });

  root.querySelector("#manualBtn").addEventListener("click", () =>
    openFormModal({
      title: "Add manual votes",
      submitLabel: "Add votes",
      size: "",
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
        const nominee = nominees.find((n) => n.id === v.nomineeId);
        store.set(KEY, [
          ...orders(),
          {
            reference: uid("MAN-", 8),
            nomineeId: nominee.id,
            categoryId: nominee.categoryId,
            votes: Number(v.votes),
            amount: 0,
            name: admin.name,
            email: "",
            status: "paid",
            source: "manual",
            reason: v.reason.trim(),
            createdAt: new Date().toISOString(),
          },
        ]);
        logAudit(
          admin,
          `Added ${v.votes} manual votes`,
          `${nominee.name} — ${v.reason.trim()}`,
        );
        notify.toast("success", "Manual votes added");
        refresh();
      },
    }),
  );
  stats();
}
