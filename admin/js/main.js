import { ROOT } from "../../shared/js/config/app.config.js";
import { html, mount } from "../../shared/js/core/dom.js";
import { store } from "../../shared/js/core/store.js";
import { notify } from "../../shared/js/ui/notify.js";
import {
  currentAdmin,
  restoreSession,
  logoutAdmin,
  getViewAs,
  setViewAs,
  clearViewAs,
} from "./services/admin-auth.service.js";
import {
  listEditions,
  getEdition,
  activeEditionId,
  setViewing,
} from "./services/edition.repo.js";
import {
  accessibleEditionIds,
  listPeople,
  listRoles,
} from "./services/team.repo.js";
import { logPreview } from "./services/audit.api.js";
import { buildAccess } from "./core/access.js";
import { renderShell, setPageHeader } from "./core/layout.js";
import { openFormModal } from "./components/modal.js";
import { rules } from "../../shared/js/core/validate.js";

/**
 * Admin page runner. Each admin HTML page sets <body data-page="name">; this loads
 * admin/js/pages/<name>.js, which exports:
 *   title · crumbs? · perm? (permission needed to open it) · default(ctx) -> html · init(root, ctx)?
 * ctx = { admin, edition, can, readOnly, access } — `can('nominees.approve')` is the one check
 * every page uses, so permissions and read-only editions are enforced in one place.
 */
const page = document.body.dataset.page;
const root = document.getElementById("admin-root");

await restoreSession();
const person = currentAdmin();

const notice = (icon, title, text) =>
  html`<div class="oga-dt__empty card py-5">
    <i class="fa-solid ${icon}"></i>
    <h2 class="h5">${title}</h2>
    <p class="mb-0">${text}</p>
  </div>`;

if (page === "login") {
  load(null);
} else if (!person) {
  location.replace(`${ROOT}admin/login.html`);
} else {
  start();
}

function start() {
  const editions = listEditions();
  const allowed = accessibleEditionIds(person, editions);
  if (!allowed.length) {
    logoutAdmin();
    location.replace(`${ROOT}admin/login.html`);
    return;
  }
  let id = store.get("admin:viewing");
  if (!allowed.includes(id))
    id = allowed.includes(activeEditionId()) ? activeEditionId() : allowed[0];
  setViewing(id);

  const viewAs = getViewAs();
  const edition = getEdition(id);
  const access = buildAccess(person, edition, viewAs);
  const realAccess = buildAccess(person, edition, null);
  const canPreview = realAccess.can("team.manage") || person.superAdmin;

  // Render the shell synchronously so AdminLTE can bind to it on DOMContentLoaded.
  renderShell(
    root,
    access,
    editions.filter((e) => allowed.includes(e.id)),
    `admin/${location.pathname.split("/").pop() || "index.html"}`,
    { canPreview },
  );

  root.addEventListener("click", async (e) => {
    const sw = e.target.closest("[data-edition]");
    if (sw) {
      setViewing(sw.dataset.edition);
      location.reload();
      return;
    }
    if (e.target.closest("#exitPreview")) {
      clearViewAs();
      logPreview("stop");
      location.reload();
      return;
    }
    if (e.target.closest("#logoutBtn")) {
      if (
        await notify.confirm({ title: "Sign out?", confirmText: "Sign out" })
      ) {
        await logoutAdmin();
        location.assign(`${ROOT}admin/login.html`);
      }
      return;
    }
    if (e.target.closest("#viewAsBtn")) openViewAs(edition);
  });
  load(access);
}

/** "View as…" — preview exactly what a role, or a specific person, can see. Always read-only. */
function openViewAs(edition) {
  const roles = listRoles()
    .filter((r) => !r.locked)
    .map((r) => ({ value: `role:${r.id}`, label: `Role — ${r.name}` }));
  const people = listPeople()
    .filter((p) => p.active && p.email && p.id !== person.id)
    .map((p) => ({ value: `person:${p.id}`, label: `Person — ${p.name}` }));
  openFormModal({
    title: "View as…",
    submitLabel: "Start preview",
    fields: [
      {
        name: "target",
        label: "Preview the admin as",
        required: true,
        options: [...roles, ...people],
        help: `Uses their access in ${edition.name} ${edition.edition}. Previews are read-only and are recorded in the audit log.`,
      },
    ],
    schema: { target: [rules.required("Choose a role or person.")] },
    async onSubmit(v) {
      const [type, tid] = v.target.split(":");
      setViewAs({ type, id: tid });
      await logPreview("start", v.target);
      location.assign(`${ROOT}admin/index.html`);
    },
  });
}

async function load(access) {
  const target = access ? document.getElementById("page") : root;
  try {
    const mod = await import(`./pages/${page}.js`);
    document.title = `${mod.title ?? "Admin"} — OGA Admin`;
    if (access) setPageHeader(mod.title ?? "", mod.crumbs ?? [mod.title]);
    if (access && mod.perm && !access.can(mod.perm)) {
      mount(
        target,
        notice(
          "fa-lock",
          "No access",
          access.previewing
            ? "This role can’t open this page."
            : "Your role doesn’t include this page.",
        ),
      );
      return;
    }
    const ctx = access
      ? {
          admin: person,
          edition: access.edition,
          can: access.can,
          readOnly: access.readOnly,
          access,
        }
      : { admin: null };
    mount(target, await mod.default(ctx));
    await mod.init?.(target, ctx);
  } catch (err) {
    console.error(err);
    mount(
      target,
      notice(
        "fa-triangle-exclamation",
        "We couldn’t load this page",
        "Please refresh and try again.",
      ),
    );
  }
}
