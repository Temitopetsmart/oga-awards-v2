import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { store } from "../../../shared/js/core/store.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openInfoModal } from "../components/modal.js";
import { logAudit } from "../services/admin-store.js";

export const title = "Messages";
export const perm = "messages";
export const crumbs = ["People", "Messages"];
const KEY = "contact-messages";

export default async function messages() {
  return html`<div class="card">
    <div class="card-header">
      <h2 class="card-title mb-0">Contact messages</h2>
    </div>
    <div id="msgTable"></div>
  </div>`;
}

export async function init(root, { admin }) {
  const rows = () =>
    store
      .get(KEY, [])
      .map((m) => ({ ...m, status: m.read ? "read" : "unread" }))
      .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
  const patch = (id, p) =>
    store.set(
      KEY,
      store.get(KEY, []).map((m) => (m.id === id ? { ...m, ...p } : m)),
    );
  const table = new DataTable(root.querySelector("#msgTable"), {
    rows: rows(),
    searchKeys: ["name", "email", "subject", "message"],
    filters: [
      {
        key: "status",
        label: "Status",
        options: [
          { value: "unread", label: "Unread" },
          { value: "read", label: "Read" },
        ],
      },
    ],
    columns: [
      {
        key: "name",
        label: "From",
        sort: true,
        render: (m) =>
          html`<strong>${m.name}</strong
            ><small class="d-block text-secondary">${m.email}</small>`,
      },
      {
        key: "subject",
        label: "Subject",
        sort: true,
        render: (m) =>
          html`<span class="${m.read ? "" : "fw-bold"}">${m.subject}</span>`,
      },
      {
        key: "status",
        label: "Status",
        sort: true,
        render: (m) => statusBadge(m.status),
      },
      {
        key: "createdAt",
        label: "Received",
        sort: true,
        render: (m) =>
          m.createdAt
            ? formatDate(m.createdAt, {
                dateStyle: "medium",
                timeStyle: "short",
              })
            : "—",
      },
    ],
    actions: [
      { key: "view", label: "Read message", icon: "fa-eye" },
      { key: "reply", label: "Reply by email", icon: "fa-reply" },
      { key: "toggle", label: "Mark read / unread", icon: "fa-envelope-open" },
      { key: "delete", label: "Delete", icon: "fa-trash" },
    ],
    empty:
      "No messages yet. They arrive from the Contact page on the public site.",
    onAction: (key, m) => handlers[key](m),
  });
  const refresh = () => table.setRows(rows());
  const handlers = {
    view(m) {
      patch(m.id, { read: true });
      refresh();
      openInfoModal({
        title: m.subject,
        body: html`<p class="text-secondary mb-3">
            From <strong>${m.name}</strong> · ${m.email}
          </p>
          <p style="white-space:pre-wrap">${m.message}</p>`,
      });
    },
    reply(m) {
      patch(m.id, { read: true });
      refresh();
      window.location.href = `mailto:${encodeURIComponent(m.email).replace("%40", "@")}?subject=${encodeURIComponent(`Re: ${m.subject}`)}`;
    },
    toggle(m) {
      patch(m.id, { read: !m.read });
      refresh();
    },
    async delete(m) {
      if (
        !(await notify.confirm({
          title: "Delete this message?",
          text: "This can’t be undone.",
          confirmText: "Delete",
          danger: true,
        }))
      )
        return;
      store.set(
        KEY,
        store.get(KEY, []).filter((x) => x.id !== m.id),
      );
      logAudit(admin, "Deleted message", m.subject);
      notify.toast("success", "Deleted");
      refresh();
    },
  };
}
