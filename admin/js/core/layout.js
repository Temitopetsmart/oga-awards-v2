import { APP, ROOT } from "../../../shared/js/config/app.config.js";
import { html, mount, safeClass } from "../../../shared/js/core/dom.js";
import { ADMIN } from "../config/admin.config.js";
import { statusBadge } from "../components/badges.js";
import { pendingNomineeCount } from "../services/nominee.repo.js";
import { unreadMessageCount, newApplicationCount } from "../services/counts.js";
import { editionLabel } from "../services/edition.repo.js";
import { effectivePerms } from "../services/team.repo.js";

const BADGES = {
  pendingNominees: pendingNomineeCount,
  unreadMessages: unreadMessageCount,
  newApplications: newApplicationCount,
};

/**
 * Renders the AdminLTE shell synchronously (must run before DOMContentLoaded so AdminLTE binds to it).
 * `access` comes from buildAccess(); `editions` are the editions this person can open.
 */
export function renderShell(root, access, editions, current, { canPreview }) {
  const { person, edition } = access;
  const items = ADMIN.menu.filter((m) => m.section || access.can(m.perm));
  const menu = items.filter(
    (m, i) => !m.section || (items[i + 1] && !items[i + 1].section),
  ); // drop empty sections
  const roleNames = person.superAdmin
    ? ["Super admin"]
    : effectivePerms(person, edition.id).roleNames;
  const initials = person.name
    .split(" ")
    .map((w) => w[0])
    .slice(0, 2)
    .join("");
  const activeEdition = editions.find((e) => e.status === "active");

  const banners = [];
  if (access.previewing)
    banners.push(
      html`<div class="oga-banner oga-banner--preview" role="status">
        <span
          ><i class="fa-solid fa-eye me-2"></i>Previewing what
          <strong>${access.previewLabel}</strong> can see. Read-only — nothing
          you do here changes data.</span
        >
        <button class="btn btn-ghost btn-sm" id="exitPreview">
          Exit preview
        </button>
      </div>`,
    );
  if (access.readOnly)
    banners.push(
      html`<div class="oga-banner oga-banner--warn" role="status">
        <span
          ><i class="fa-solid fa-lock me-2"></i
          ><strong>${editionLabel(edition)}</strong> is ${edition.status}. Its
          records are kept for reference and can’t be changed.</span
        >
        ${activeEdition
          ? html`<button
              class="btn btn-ghost btn-sm"
              data-edition="${activeEdition.id}"
            >
              Go to ${editionLabel(activeEdition)}
            </button>`
          : ""}
      </div>`,
    );
  if (edition.status === "draft")
    banners.push(
      html`<div class="oga-banner" role="status">
        <i class="fa-solid fa-pen-ruler me-2"></i
        ><span
          ><strong>${editionLabel(edition)}</strong> is a draft — it isn’t
          visible to the public until it’s activated.</span
        >
      </div>`,
    );

  mount(
    root,
    html` <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
          <ul class="navbar-nav align-items-center">
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
            <li class="nav-item dropdown oga-edswitch">
              <button
                class="btn btn-ghost"
                data-bs-toggle="dropdown"
                aria-label="Switch edition"
                aria-expanded="false"
                id="edSwitch"
              >
                <i class="fa-solid fa-clock-rotate-left"></i
                ><span>${editionLabel(edition)}</span>${statusBadge(
                  edition.status,
                )}<i class="fa-solid fa-chevron-down small"></i>
              </button>
              <div class="dropdown-menu">
                <h6 class="dropdown-header">Switch edition</h6>
                ${editions.map(
                  (e) =>
                    html`<button
                      class="dropdown-item ${e.id === edition.id
                        ? "is-current"
                        : ""}"
                      data-edition="${e.id}"
                    >
                      <span
                        >${editionLabel(e)}
                        <small class="text-secondary"
                          >${e.year ?? ""}</small
                        ></span
                      >${statusBadge(e.status)}
                    </button>`,
                )}
                ${access.can("editions.view")
                  ? html`<div class="dropdown-divider"></div>
                      <a class="dropdown-item" href="${ROOT}admin/editions.html"
                        ><span>Manage editions</span
                        ><i class="fa-solid fa-arrow-right small"></i
                      ></a>`
                  : ""}
              </div>
            </li>
          </ul>
          <ul class="navbar-nav ms-auto align-items-center">
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
                  >${initials}</span
                >
                <span class="d-none d-md-inline">${person.name}</span>
              </a>
              <div class="dropdown-menu dropdown-menu-end">
                <div class="px-3 py-2">
                  <strong class="d-block">${person.name}</strong
                  ><small class="text-secondary"
                    >${roleNames.join(", ") ||
                    "No admin role in this edition"}</small
                  >
                </div>
                <div class="dropdown-divider"></div>
                ${canPreview
                  ? html`<button class="dropdown-item" id="viewAsBtn">
                      <i class="fa-solid fa-eye me-2"></i>View as…
                    </button>`
                  : ""}
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
                        class="nav-link ${m.href === current ? "active" : ""}"
                        href="${ROOT + m.href}"
                        ${m.href === current ? html`aria-current="page"` : ""}
                      >
                        <i class="nav-icon fa-solid ${safeClass(m.icon)}"></i>
                        <p>${m.label}</p>
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
          <div class="container-fluid">
            <div id="banners">${banners}</div>
            <div id="page"></div>
          </div>
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
