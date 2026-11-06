import { adminApi } from "./admin-api.js";
import { viewing } from "./scope.js";

/** Admin → Email: delivery status, templates, test sends and the outbox. */
const ed = () => viewing();
const enc = encodeURIComponent;

export const loadEmail = () => adminApi(`/admin/email?edition=${enc(ed())}`);

export const saveTemplate = (key, subject, body) =>
  adminApi(`/admin/email/templates/${key}`, {
    method: "PUT",
    body: { edition: ed(), subject, body },
  });

export const resetTemplate = (key) =>
  adminApi(`/admin/email/templates/${key}`, {
    method: "DELETE",
    body: { edition: ed() },
  });

export const previewTemplate = (key, subject, body) =>
  adminApi(`/admin/email/templates/${key}/preview`, {
    method: "POST",
    body: { edition: ed(), subject, body },
  });

/** template, subject and body are optional: without them a plain test message is sent. */
export const sendTestEmail = (to, template = "", subject = "", body = "") =>
  adminApi("/admin/email/test", {
    method: "POST",
    body: { edition: ed(), to, template, subject, body },
  });

export const loadOutbox = ({ status = "", type = "", q = "", page = 1 }) =>
  adminApi(
    `/admin/email/outbox?edition=${enc(ed())}&status=${enc(status)}&type=${enc(type)}&q=${enc(q)}&page=${page}`,
  );

export const sendQueued = () =>
  adminApi("/admin/email/outbox/send", {
    method: "POST",
    body: { edition: ed() },
  });

export const retryEmail = (id) =>
  adminApi(`/admin/email/outbox/${id}/retry`, {
    method: "POST",
    body: { edition: ed() },
  });
