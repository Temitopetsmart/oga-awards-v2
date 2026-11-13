import { adminApi } from "./admin-api.js";

/** The signed-in admin's own account (Phase 18). */
export const changeMyPassword = ({ current, password }) =>
  adminApi("/admin/account/password", {
    method: "PUT",
    body: { current, password },
  });
export const signOutEverywhere = () =>
  adminApi("/admin/account/sign-out-everywhere", { method: "POST", body: {} });
export const updateMyName = (name) =>
  adminApi("/admin/account/profile", { method: "PUT", body: { name } });
/** A Super admin sends a reset link to someone else. */
export const sendResetLink = (personId) =>
  adminApi(`/admin/people/${personId}/password-reset`, {
    method: "POST",
    body: {},
  });
