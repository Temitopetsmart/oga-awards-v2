import { adminApi } from "./admin-api.js";
import { restoreSession } from "./admin-auth.service.js";

/** Contact messages on the server. After a change the sidebar counts are refreshed too. */
let rows = [];

export async function loadMessages(editionId) {
  rows = (
    await adminApi(`/admin/messages?edition=${encodeURIComponent(editionId)}`)
  ).messages;
  return rows;
}
export const listMessages = () => rows;

export async function setMessageRead(editionId, id, read) {
  await adminApi(`/admin/messages/${id}`, {
    method: "PATCH",
    body: { edition: editionId, read },
  });
  await loadMessages(editionId);
  restoreSession();
}

export async function deleteMessage(editionId, id) {
  await adminApi(`/admin/messages/${id}`, {
    method: "DELETE",
    body: { edition: editionId },
  });
  await loadMessages(editionId);
  restoreSession();
}
