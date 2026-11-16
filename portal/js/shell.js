import { ROOT, APP } from "../../shared/js/config/app.config.js";
import { html } from "../../shared/js/core/dom.js";
import { mediaUrl } from "../../shared/js/core/media.js";
import { notify } from "../../shared/js/ui/notify.js";
import { go } from "../../shared/js/core/nav.js";
import {
  logoutNominee,
  switchNomineeEntry,
} from "../../shared/js/services/auth.service.js";

/** The portal menu. `badge` names the little number to show (see data.js `attention`). */
export const NAV = [
  { key: "home", label: "Home", icon: "fa-house", href: "portal/" },
  {
    key: "entries",
    label: "My entries",
    icon: "fa-list-check",
    href: "portal/entries",
    badge: "entries",
  },
  {
    key: "profile",
    label: "My profile",
    icon: "fa-user",
    href: "portal/profile",
    badge: "profile",
  },
  {
    key: "promote",
    label: "Share & promote",
    icon: "fa-bullhorn",
    href: "portal/promote",
  },
  { key: "pass", label: "Access Pass", icon: "fa-ticket", href: "portal/pass" },
  { key: "updates", label: "Updates", icon: "fa-bell", href: "portal/updates" },
  {
    key: "account",
    label: "Account",
    icon: "fa-gear",
    href: "portal/account",
    badge: "account",
  },
];

const initials = (name) =>
  name
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "")
    .join("");

const STATE_LABEL = {
  nominations: "Nominations open",
  voting: "Voting open",
  "voting-closed": "Counting votes",
  results: "Winners announced",
};

/** Wraps a page in the portal frame: sidebar, top bar, and (for admins previewing) a read-only banner. */
export function renderShell({ page, title, content, p }) {
  const { me, ctx, readOnly, attention, entries } = p;
  const multi = !readOnly && entries.length > 1;
  return html`<div class="pt-shell">
    <aside
      class="pt-sidebar offcanvas-lg offcanvas-start"
      tabindex="-1"
      id="ptSidebar"
      aria-label="Portal menu"
    >
      <div class="offcanvas-body">
        <a class="pt-brand" href="${ROOT}portal/">
          <img src="${ROOT}assets/images/logo/favicon.png" alt="" />
          <span
            ><strong>${APP.brand?.name ?? "OGA Awards"}</strong
            ><small>Nominee Portal</small></span
          >
        </a>
        <div class="pt-me">
          ${me.photo
            ? html`<img src="${mediaUrl(me.photo)}" alt="" />`
            : html`<span class="pt-avatar" aria-hidden="true"
                >${initials(me.name)}</span
              >`}
          <div class="min-w-0">
            <strong>${me.name}</strong
            ><span class="pt-chip pt-chip--${p.status.tone}"
              >${p.status.label}</span
            >
          </div>
        </div>
        ${multi
          ? html`<div>
              <label class="form-label small text-secondary mb-1" for="ptEntry"
                >Active entry</label
              >
              <select class="form-select form-select-sm" id="ptEntry">
                ${entries.map(
                  (e) =>
                    html`<option
                      value="${e.id}"
                      ${e.id === me.id ? "selected" : ""}
                    >
                      ${[e.occasionId, p.catName(e), e.status]
                        .filter(Boolean)
                        .join(" — ")}
                    </option>`,
                )}
              </select>
            </div>`
          : ""}
        <nav class="pt-nav" aria-label="Portal">
          ${NAV.map(
            (n) =>
              html`<a
                href="${ROOT}${n.href}${readOnly && p.viewAsId
                  ? `?viewAs=${encodeURIComponent(p.viewAsId)}`
                  : ""}"
                class="${n.key === page ? "active" : ""}"
                ${n.key === page ? 'aria-current="page"' : ""}
              >
                <i class="fa-solid ${n.icon}" aria-hidden="true"></i>${n.label}
                ${n.badge && attention[n.badge]
                  ? html`<span class="pt-dot">${attention[n.badge]}</span>`
                  : ""}
              </a>`,
          )}
        </nav>
        <div class="pt-side-foot">
          <a href="${ROOT}"
            ><i
              class="fa-solid fa-arrow-up-right-from-square"
              aria-hidden="true"
            ></i
            >View the public site</a
          >
          ${readOnly
            ? ""
            : html`<button type="button" id="ptLogout">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i
                >Sign out
              </button>`}
        </div>
      </div>
    </aside>
    <div class="pt-main">
      ${readOnly
        ? html`<div class="pt-preview">
            <i class="fa-solid fa-eye me-2"></i>Admin preview: ${me.name}’s
            portal is read-only.
          </div>`
        : ""}
      <header class="pt-top">
        <button
          class="btn btn-ghost btn-sm d-lg-none"
          type="button"
          data-bs-toggle="offcanvas"
          data-bs-target="#ptSidebar"
          aria-controls="ptSidebar"
          aria-label="Open menu"
        >
          <i class="fa-solid fa-bars"></i>
        </button>
        <h1>${title}</h1>
        <div class="ms-auto">
          <span class="pt-chip"
            >${ctx.occasion.name} ${ctx.occasion.edition}</span
          >
          <span class="pt-chip pt-chip--ok d-none d-sm-inline-block"
            >${STATE_LABEL[ctx.state] ?? ctx.state}</span
          >
        </div>
      </header>
      <main class="pt-content" id="ptContent" tabindex="-1">
        ${content}
        <div id="portalAdSlot" class="pt-ad" aria-label="Sponsors"></div>
      </main>
    </div>
  </div>`;
}

/** Wires the frame (sign out, switching entries). Page content wires itself in its own init(). */
export function wireShell(root) {
  root.querySelector("#ptLogout")?.addEventListener("click", async () => {
    if (await notify.confirm({ title: "Sign out?", confirmText: "Sign out" })) {
      logoutNominee();
      go("login");
    }
  });
  root.querySelector("#ptEntry")?.addEventListener("change", (e) => {
    try {
      switchNomineeEntry(e.target.value);
      location.reload();
    } catch (err) {
      notify.failure(err);
    }
  });
}
