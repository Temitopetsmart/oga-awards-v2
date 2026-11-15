import { store } from "../../../shared/js/core/store.js";
import { adminApi } from "./admin-api.js";

/** Ticket sellers, allocations, cash received and free bulk batches. The server sets every price and checks every limit. */
const viewing = () => store.get("admin:viewing");
const q = () => `edition=${encodeURIComponent(viewing())}`;
const body = (extra = {}) => ({ edition: viewing(), ...extra });
const send = (path, method, extra) =>
  adminApi(path, { method, body: body(extra) });

// a seller's own page
export const fetchMySales = () => adminApi(`/admin/sales/me?${q()}`);
export const takeTickets = (typeId, qty, label) =>
  send("/admin/sales/generate", "POST", { typeId, qty, label });
export const confirmSale = (reference) =>
  send(`/admin/sales/orders/${encodeURIComponent(reference)}/confirm`, "POST");
export const fetchBatch = (reference) =>
  adminApi(`/admin/sales/orders/${encodeURIComponent(reference)}?${q()}`);
export const nameSeat = (code, name) =>
  send(`/admin/sales/tickets/${encodeURIComponent(code)}/holder`, "PATCH", {
    name,
  });
export const returnUnit = (groupCode) =>
  send(`/admin/sales/groups/${encodeURIComponent(groupCode)}/return`, "POST");

// managers
export const fetchOverview = () => adminApi(`/admin/sales/overview?${q()}`);
export const saveAllocation = (data) =>
  send("/admin/sales/allocations", "PUT", data);
export const deleteAllocation = (id) =>
  send(`/admin/sales/allocations/${id}`, "DELETE");
export const addRemittance = (data) =>
  send("/admin/sales/remittances", "POST", data);
export const deleteRemittance = (id) =>
  send(`/admin/sales/remittances/${id}`, "DELETE");
export const makeBulk = (data) => send("/admin/sales/bulk", "POST", data);
