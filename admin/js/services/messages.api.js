import { adminApi } from "./admin-api.js";
import { restoreSession } from "./admin-auth.service.js";
import { pageQuery } from "./paging.js";

/** Contact messages on the server, one page at a time. After a change the sidebar counts are refreshed too. */
export async function fetchMessagesPage(editionId, params) {
  const r = await adminApi(
    `/admin/messages?edition=${encodeURIComponent(editionId)}&${pageQuery(params)}`,
  );
  return { rows: r.messages, total: r.total, page: r.page, pages: r.pages };
}

export async function setMessageRead(editionId, id, read) {
  await adminApi(`/admin/messages/${id}`, {
    method: "PATCH",
    body: { edition: editionId, read },
  });
  restoreSession();
}

export async function deleteMessage(editionId, id) {
  await adminApi(`/admin/messages/${id}`, {
    method: "DELETE",
    body: { edition: editionId },
  });
  restoreSession();
}
