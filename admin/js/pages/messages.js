import { html } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { statusBadge } from "../components/badges.js";
import { openInfoModal } from "../components/modal.js";
import {
  loadMessages,
  listMessages,
  setMessageRead,
  deleteMessage,
} from "../services/messages.api.js";

export const title = "Messages";
export const perm = "messages.view";
export const crumbs = ["People", "Messages"];

export default async function messages() {
  return html`<div class="card">
    <div class="card-header">
      <h2 class="card-title mb-0">Contact messages</h2>
    </div>
    <div id="msgTable"></div>
  </div>`;
}

export async function init(root, { edition, can }) {
  await loadMessages(edition.id);
  const rows = () =>
    listMessages().map((m) => ({ ...m, status: m.read ? "read" : "unread" }));
  const manage = can("messages.manage");
  const fail = (e) => notify.failure(e);

  const table = new DataTable(root.querySelector("#msgTable"), {
    can,
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
      {
        key: "view",
        label: "Read message",
        icon: "fa-eye",
        perm: "messages.view",
      },
      {
        key: "reply",
        label: "Reply by email",
        icon: "fa-reply",
        perm: "messages.manage",
      },
      {
        key: "toggle",
        label: "Mark read / unread",
        icon: "fa-envelope-open",
        perm: "messages.manage",
      },
      {
        key: "delete",
        label: "Delete",
        icon: "fa-trash",
        perm: "messages.manage",
      },
    ],
    empty: "No messages yet in this edition.",
    onAction: (key, m) => handlers[key](m),
  });
  const refresh = () => table.setRows(rows());

  const mark = async (m, read) => {
    try {
      await setMessageRead(edition.id, m.id, read);
      refresh();
    } catch (e) {
      fail(e);
    }
  };

  const handlers = {
    async view(m) {
      openInfoModal({
        title: m.subject,
        body: html`<p class="text-secondary mb-3">
            From <strong>${m.name}</strong> · ${m.email}
          </p>
          <p style="white-space:pre-wrap">${m.message}</p>`,
      });
      if (manage && !m.read) await mark(m, true);
    },
    async reply(m) {
      if (!m.read) await mark(m, true);
      window.location.href = `mailto:${encodeURIComponent(m.email).replace("%40", "@")}?subject=${encodeURIComponent(`Re: ${m.subject}`)}`;
    },
    toggle: (m) => mark(m, !m.read),
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
      try {
        await deleteMessage(edition.id, m.id);
        notify.toast("success", "Deleted");
        refresh();
      } catch (e) {
        fail(e);
      }
    },
  };
}
