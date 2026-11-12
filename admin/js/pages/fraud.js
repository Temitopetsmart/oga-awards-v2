import { html } from "../../../shared/js/core/dom.js";
import { rules } from "../../../shared/js/core/validate.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { number } from "../../../shared/js/core/format.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openFormModal } from "../components/modal.js";
import { editionLabel } from "../services/edition.repo.js";
import {
  fetchFraud,
  saveFraudRules,
  reviewSignal,
  addBlock,
  removeBlock,
} from "../services/fraud.api.js";

export const title = "Fraud checks";
export const perm = "fraud.view";
export const crumbs = ["Awards", "Fraud checks"];

const KIND = {
  burst: "Burst of votes",
  ip_cluster: "Many orders, one address",
  domain_cluster: "Many emails, one odd domain",
};

export default async function fraud({ edition, can }) {
  const d = await fetchFraud(edition.id);
  const r = d.rules;
  const lock = can("fraud.manage") ? "" : "disabled";
  return html` <div class="oga-banner" role="note">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Signals for
        <strong>${editionLabel(edition)}</strong> are worked out from paid
        votes. Nothing is hidden or changed by looking. Review each one: dismiss
        it if it is fine, or cancel its votes. A whole campus can share one
        address, so the per-address limit is off until you set it.</span
      >
    </div>
    <div class="card mb-3">
      <div class="card-header">
        <h2 class="card-title mb-0">
          Signals <span class="small text-secondary">(${d.open} open)</span>
        </h2>
      </div>
      <div id="signalTable"></div>
    </div>
    <div class="card mb-3">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Blocked voters</h2>
        ${can("fraud.manage")
          ? html`<button class="btn btn-gold btn-sm" id="addBlock">
              <i class="fa-solid fa-ban me-1"></i>Block…
            </button>`
          : ""}
      </div>
      <div id="blockTable"></div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title mb-0">Rules</h2></div>
      <div class="card-body">
        <form id="rulesForm" novalidate>
          <fieldset ${lock}>
            <div class="row">
              ${field({
                label: "Orders per address per hour",
                name: "ipMaxOrdersPerHour",
                type: "number",
                required: true,
                value: r.ipMaxOrdersPerHour,
                col: "col-md-4",
                help: "0 = no limit. Visitors over it are asked to wait. Many students can share one address, so keep it high.",
              })}
              ${field({
                label: "Burst: votes",
                name: "burstVotes",
                type: "number",
                required: true,
                value: r.burstVotes,
                col: "col-md-4",
                help: "Flag a nominee who gets this many votes inside the window. 0 = off.",
              })}
              ${field({
                label: "Burst: window (minutes)",
                name: "burstMinutes",
                type: "number",
                required: true,
                value: r.burstMinutes,
                col: "col-md-4",
                help: "1 to 120.",
              })}
              ${field({
                label: "Address cluster: orders",
                name: "ipClusterOrders",
                type: "number",
                required: true,
                value: r.ipClusterOrders,
                col: "col-md-4",
                help: "Flag this many paid orders from one address for one nominee. 0 = off.",
              })}
              ${field({
                label: "Domain cluster: emails",
                name: "domainClusterEmails",
                type: "number",
                required: true,
                value: r.domainClusterEmails,
                col: "col-md-4",
                help: "Flag this many different emails at one unusual domain for one nominee. Gmail, Yahoo, Outlook and similar are ignored. 0 = off.",
              })}
            </div>
            <button class="btn btn-gold" type="submit">Save rules</button>
          </fieldset>
        </form>
      </div>
    </div>`;
}

export async function init(root, { edition, can }) {
  let data = await fetchFraud(edition.id);
  const manage = can("fraud.manage");
  const showSignal = (s) => ({
    ...s,
    kindLabel: KIND[s.kind] ?? s.kind,
    state: s.status === "open" ? "pending" : "hidden",
  });
  const signals = new DataTable(root.querySelector("#signalTable"), {
    can,
    rows: data.signals.map(showSignal).map((s) => ({ ...s, id: s.key })),
    searchKeys: ["nominee", "detail", "category"],
    columns: [
      { key: "kindLabel", label: "Signal", sort: true },
      {
        key: "nominee",
        label: "Nominee",
        sort: true,
        render: (s) =>
          html`<div>
            <strong>${s.nominee}</strong
            ><small class="d-block text-secondary">${s.category}</small>
          </div>`,
      },
      {
        key: "votes",
        label: "Votes",
        sort: true,
        render: (s) => number(s.votes),
      },
      { key: "orders", label: "Orders", sort: true },
      { key: "detail", label: "What we saw" },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (s) =>
          statusBadge(
            s.state,
            s.status === "open"
              ? "Open"
              : s.status === "dismissed"
                ? "Dismissed"
                : "Acted on",
          ),
      },
    ],
    actions: manage
      ? [
          {
            key: "dismiss",
            label: "Dismiss: it is fine",
            icon: "fa-check",
            when: (s) => s.status === "open",
          },
          {
            key: "void",
            label: "Cancel these votes",
            icon: "fa-ban",
            when: (s) => s.status === "open",
          },
        ]
      : [],
    empty: "No signals. Either all is well, or the rules are set high or off.",
    onAction: (key, s) => handlers[key](s),
  });
  const blocks = new DataTable(root.querySelector("#blockTable"), {
    can,
    rows: data.blocks,
    searchKeys: ["value", "reason"],
    columns: [
      {
        key: "kind",
        label: "Type",
        sort: true,
        render: (b) =>
          ({ ip: "IP address", email: "Email", domain: "Domain" })[b.kind],
      },
      { key: "value", label: "Blocked", sort: true },
      { key: "reason", label: "Reason", render: (b) => b.reason || "—" },
      { key: "by", label: "Added by", render: (b) => b.by ?? "—" },
    ],
    actions: manage
      ? [{ key: "remove", label: "Remove block", icon: "fa-trash" }]
      : [],
    empty: "Nobody is blocked.",
    onAction: (key, b) => handlers.removeBlock(b),
  });
  const refresh = async () => {
    data = await fetchFraud(edition.id);
    signals.setRows(
      data.signals.map(showSignal).map((s) => ({ ...s, id: s.key })),
    );
    blocks.setRows(data.blocks);
  };

  const handlers = {
    async dismiss(s) {
      const done = await notify.prompt({
        title: "Dismiss this signal?",
        text: "It stays in the list as dismissed. Say why it is fine.",
        placeholder: "For example: a real class, checked with the lecturer",
        confirmText: "Dismiss",
        autocapitalize: "sentences",
        save: (note) =>
          reviewSignal(edition.id, { key: s.key, action: "dismiss", note }),
      });
      if (done) {
        notify.toast("info", "Dismissed");
        await refresh();
      }
    },
    async void(s) {
      const canBlock = s.kind === "ip_cluster" || s.kind === "domain_cluster";
      let block = false;
      if (canBlock) {
        block = await notify.confirm({
          title: "Also block them from voting again?",
          text:
            s.kind === "ip_cluster"
              ? "Block this address for the rest of this edition. Careful: others on the same network are blocked too."
              : "Block this email domain for the rest of this edition.",
          confirmText: "Cancel votes and block",
          cancelText: "Only cancel the votes",
        });
      }
      const done = await notify.prompt({
        title: `Cancel ${number(s.votes)} votes for ${s.nominee}?`,
        text: "The paid orders behind this signal become void and the nominee’s total goes down. The money is not refunded automatically. The reason is kept in the activity log.",
        placeholder: "Why are these votes being cancelled?",
        confirmText: "Cancel the votes",
        autocapitalize: "sentences",
        save: (note) =>
          reviewSignal(edition.id, { key: s.key, action: "void", note, block }),
      });
      if (done) {
        notify.toast("success", "Votes cancelled");
        await refresh();
      }
    },
    async removeBlock(b) {
      if (
        !(await notify.confirm({
          title: `Unblock ${b.value}?`,
          confirmText: "Unblock",
        }))
      )
        return;
      await removeBlock(edition.id, b.id);
      notify.toast("info", "Unblocked");
      await refresh();
    },
  };

  root.querySelector("#addBlock")?.addEventListener("click", () =>
    openFormModal({
      title: "Block a voter",
      fields: [
        {
          name: "kind",
          label: "What to block",
          required: true,
          options: [
            { value: "ip", label: "An IP address" },
            { value: "email", label: "One email address" },
            { value: "domain", label: "An email domain (example.com)" },
          ],
        },
        { name: "value", label: "Value", required: true },
        { name: "reason", label: "Reason (optional)" },
      ],
      values: { kind: "ip" },
      schema: {
        kind: [rules.required()],
        value: [rules.required()],
        reason: [rules.max(255)],
      },
      async onSubmit(v) {
        await addBlock(edition.id, {
          kind: v.kind,
          value: v.value.trim(),
          reason: (v.reason ?? "").trim(),
        });
        notify.toast("success", "Blocked");
        await refresh();
      },
    }),
  );

  const whole = (min, max) =>
    rules.custom((v) =>
      Number.isInteger(Number(v)) && Number(v) >= min && Number(v) <= max
        ? null
        : `Enter a whole number from ${min} to ${max}.`,
    );
  bindForm(root.querySelector("#rulesForm"), {
    schema: {
      ipMaxOrdersPerHour: [rules.required(), whole(0, 1000)],
      burstVotes: [rules.required(), whole(0, 1000000)],
      burstMinutes: [rules.required(), whole(1, 120)],
      ipClusterOrders: [rules.required(), whole(0, 1000)],
      domainClusterEmails: [rules.required(), whole(0, 1000)],
    },
    async onSubmit(v) {
      await saveFraudRules(
        edition.id,
        Object.fromEntries(Object.entries(v).map(([k, x]) => [k, Number(x)])),
      );
      notify.toast("success", "Rules saved");
      await refresh();
    },
  });
}
