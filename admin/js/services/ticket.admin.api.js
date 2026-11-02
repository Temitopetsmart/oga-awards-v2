import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";

/** Tickets, ticket types and the gate scanner on the server. */
const viewing = () => store.get("admin:viewing");
const body = (extra = {}) => ({ edition: viewing(), ...extra });

/** { orders, tickets } of the edition. Money is 0 unless you may see finances. */
export async function fetchTickets(editionId = viewing()) {
  try {
    const r = await adminApi(`/admin/tickets?edition=${encodeURIComponent(editionId)}`);
    return { orders: r.orders, tickets: r.tickets };
  } catch {
    return { orders: [], tickets: [] };
  }
}

export async function fetchTicketTypes(editionId = viewing()) {
  try {
    return (await adminApi(`/admin/ticket-types?edition=${encodeURIComponent(editionId)}`)).types;
  } catch {
    return [];
  }
}

export const createTicketType = (data) =>
  adminApi("/admin/ticket-types", { method: "POST", body: body(data) });
export const updateTicketType = (id, data) =>
  adminApi(`/admin/ticket-types/${id}`, { method: "PATCH", body: body(data) });
export const deleteTicketType = (id) =>
  adminApi(`/admin/ticket-types/${id}`, { method: "DELETE", body: body() });

/** Manual check-in (used = true) or undo (used = false). */
export const overrideTicket = (code, used) =>
  adminApi(`/admin/tickets/${encodeURIComponent(code)}/override`, {
    method: "POST",
    body: body({ used }),
  });

/** Name the table for everyone who came together (one group). Empty clears it. */
export const setTicketTable = (groupCode, tableLabel) =>
  adminApi(`/admin/ticket-groups/${encodeURIComponent(groupCode)}`, {
    method: "PATCH",
    body: body({ tableLabel }),
  });

/** One gate scan. Answers { result: valid|used|bad|void|wrong_edition, detail, ticket, group }. */
export const scanTicket = (code) =>
  adminApi("/admin/scan", { method: "POST", body: body({ code }) });

/** { issued, checkedIn, recent: [...] } for the scanner screen. */
export const fetchScanSummary = (editionId = viewing()) =>
  adminApi(`/admin/scan?edition=${encodeURIComponent(editionId)}`);