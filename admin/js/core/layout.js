import { APP, ROOT } from "../../../shared/js/config/app.config.js";
import { html, mount, safeClass } from "../../../shared/js/core/dom.js";
import { ADMIN } from "../config/admin.config.js";
import { can, roleLabel } from "../services/admin-auth.service.js";
import { pendingNomineeCount } from "../services/nominee.repo.js";
import { unreadMessageCount, newApplicationCount } from "../services/counts.js";

const BADGES = {
  pendingNominees: pendingNomineeCount,
  unreadMessages: unreadMessageCount,
  newApplications: newApplicationCount,
};

/** Renders the AdminLTE shell synchronously (must run before DOMContentLoaded so AdminLTE binds to it). */
export function renderShell(root, admin, current) {
  const items = ADMIN.menu.filter((m) => m.section || can(admin, m.perm));
  // drop section headers that have no items under them
  const menu = items.filter(
    (m, i) => !m.section || (items[i + 1] && !items[i + 1].section),
  );

  mount(
    root,
    html` <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
          <ul class="navbar-nav">
            <li class="nav-item">
              <a
                class="nav-link"
                data-lte-toggle="sidebar"
                href="#"
                role="button"
                aria-label="Toggle sidebar"
                ><i class="fa-solid fa-bars"></i
              ></a>
            </li>
            <li class="nav-item d-none d-md-block">
              <a
                class="nav-link"
                href="${ROOT}index.html"
                target="_blank"
                rel="noopener"
                ><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>View
                site</a
              >
            </li>
          </ul>
          <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown">
              <a
                class="nav-link d-flex align-items-center gap-2"
                data-bs-toggle="dropdown"
                href="#"
                role="button"
                aria-label="Account menu"
              >
                <span
                  class="oga-avatar"
                  style="width:2rem;height:2rem;font-size:.8rem;border-width:1px"
                  >${admin.name
                    .split(" ")
                    .map((w) => w[0])
                    .slice(0, 2)
                    .join("")}</span
                >
                <span class="d-none d-md-inline">${admin.name}</span>
              </a>
              <div class="dropdown-menu dropdown-menu-end">
                <div class="px-3 py-2">
                  <strong class="d-block">${admin.name}</strong
                  ><small class="text-secondary">${roleLabel(admin)}</small>
                </div>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item" id="logoutBtn">
                  <i class="fa-solid fa-right-from-bracket me-2"></i>Sign out
                </button>
              </div>
            </li>
          </ul>
        </div>
      </nav>

      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
          <a class="brand-link" href="${ROOT}admin/index.html">
            <img src="${ROOT}${APP.brand.logo}" alt="" id="brandLogo" /><span
              ><em>OGA</em> Admin</span
            ></a
          >
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2" aria-label="Main navigation">
            <ul class="nav sidebar-menu flex-column" id="navigation">
              ${menu.map((m) =>
                m.section
                  ? html`<li class="nav-header">${m.section}</li>`
                  : html`<li class="nav-item">
                      <a
                        class="nav-link ${m.href === current
                          ? "active"
                          : ""} ${m.soon ? "disabled" : ""}"
                        href="${m.soon ? "#" : ROOT + m.href}"
                        ${m.soon
                          ? html`aria-disabled="true" tabindex="-1"`
                          : ""}
                        ${m.href === current ? html`aria-current="page"` : ""}
                      >
                        <i class="nav-icon fa-solid ${safeClass(m.icon)}"></i>
                        <p>${m.label}</p>
                        ${m.soon
                          ? html`<span class="badge text-bg-secondary"
                              >Soon</span
                            >`
                          : ""}
                        ${m.badge && BADGES[m.badge]()
                          ? html`<span class="badge text-bg-warning"
                              >${BADGES[m.badge]()}</span
                            >`
                          : ""}</a
                      >
                    </li>`,
              )}
            </ul>
          </nav>
        </div>
      </aside>

      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="row align-items-center">
              <div class="col-sm-6">
                <h1 class="mb-0" id="pageTitle">&nbsp;</h1>
              </div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end mb-0" id="pageCrumbs"></ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid" id="page"></div>
        </div>
      </main>
      <footer class="app-footer">
        <div class="float-end d-none d-sm-inline">
          Demo data — stored in this browser
        </div>
        <strong>${APP.brand.name}</strong> admin
      </footer>
    </div>`,
  );
  root
    .querySelector("#brandLogo")
    ?.addEventListener("error", (e) => e.target.remove());
}

export function setPageHeader(title, crumbs = []) {
  document.getElementById("pageTitle").textContent = title;
  mount(
    document.getElementById("pageCrumbs"),
    html` <li class="breadcrumb-item">
        <a href="${ROOT}admin/index.html">Admin</a>
      </li>
      ${crumbs.map(
        (c) =>
          html`<li class="breadcrumb-item active" aria-current="page">
            ${c}
          </li>`,
      )}`,
  );
}
