import { html } from "../../../shared/js/core/dom.js";

const MAP = {
  pending: ["warning", "Pending"],
  approved: ["success", "Approved"],
  rejected: ["danger", "Rejected"],
  active: ["success", "Active"],
  inactive: ["muted", "Inactive"],
  paid: ["success", "Paid"],
  void: ["danger", "Void"],
  new: ["warning", "New"],
  accepted: ["success", "Accepted"],
  declined: ["danger", "Declined"],
  unread: ["warning", "Unread"],
  read: ["muted", "Read"],
  used: ["muted", "Checked in"],
  unused: ["success", "Not used"],
  live: ["success", "Live"],
  hidden: ["muted", "Hidden"],
  draft: ["warning", "Draft"],
  completed: ["muted", "Completed"],
  archived: ["muted", "Archived"],
};

/** <span class="oga-status ..."> for a status key. Unknown keys render as a neutral badge. */
export const statusBadge = (status, label) => {
  const [tone, text] = MAP[status] ?? ["muted", status];
  return html`<span class="oga-status oga-status--${tone}"
    >${label ?? text}</span
  >`;
};
