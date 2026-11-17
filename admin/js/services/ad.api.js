import { store } from "../../../shared/js/core/store.js";
import { adminApi, adminUpload } from "./admin-api.js";

/** Advertising: ads, packages, settings and reports. The server decides every price, date and permission. */
const viewing = () => store.get("admin:viewing");
const q = (extra = "") => `edition=${encodeURIComponent(viewing())}${extra}`;
const send = (path, method, body = {}) =>
  adminApi(path, { method, body: { edition: viewing(), ...body } });
const upload = (path, fields, files = {}) => {
  const f = new FormData();
  f.set("edition", viewing());
  for (const [k, v] of Object.entries(fields))
    if (Array.isArray(v)) v.forEach((x) => f.append(`${k}[]`, x));
    else if (v !== undefined && v !== null) f.set(k, v);
  for (const [k, file] of Object.entries(files)) if (file) f.set(k, file);
  return adminUpload(path, f);
};

export const fetchAds = ({
  status = "",
  q: text = "",
  page = 1,
  perPage = 25,
}) =>
  adminApi(
    `/admin/ads?${q(`&status=${encodeURIComponent(status)}&q=${encodeURIComponent(text)}&page=${page}&perPage=${perPage}`)}`,
  );
export const fetchAd = (id) => adminApi(`/admin/ads/${id}?${q()}`);
export const fetchSetup = () => adminApi(`/admin/ads/categories?${q()}`);
export const fetchReport = (from, to) =>
  adminApi(
    `/admin/ads/report?${q(`&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)}`,
  );

export const approveAd = (id, data) =>
  send(`/admin/ads/${id}/approve`, "POST", data);
export const rejectAd = (id, note) =>
  send(`/admin/ads/${id}/reject`, "POST", { note });
export const removeAd = (id, reason) =>
  send(`/admin/ads/${id}/remove`, "POST", { reason });
export const extendAd = (id, days) =>
  send(`/admin/ads/${id}/extend`, "POST", { days });
export const markAdPaid = (id, note) =>
  send(`/admin/ads/${id}/mark-paid`, "POST", { note });
export const markRefunded = (id, note) =>
  send(`/admin/ads/${id}/refunded`, "POST", { note });
export const editAd = (id, data) => send(`/admin/ads/${id}`, "PATCH", data);
export const changeMedia = (id, fields, files) =>
  upload(`/admin/ads/${id}/media`, fields, files);
export const createAd = (fields, files) => upload("/admin/ads", fields, files);

export const saveCategory = (id, data) =>
  id
    ? send(`/admin/ads/categories/${id}`, "PATCH", data)
    : send("/admin/ads/categories", "POST", data);
export const deleteCategory = (id) =>
  send(`/admin/ads/categories/${id}`, "DELETE");
export const saveAdSettings = (value) =>
  send("/admin/ads/settings", "PUT", { value });
