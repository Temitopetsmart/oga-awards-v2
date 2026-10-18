import { ROOT } from "../../shared/js/config/app.config.js";
import { html, mount } from "../../shared/js/core/dom.js";
import { notify } from "../../shared/js/ui/notify.js";
import {
  currentAdmin,
  logoutAdmin,
  can,
} from "./services/admin-auth.service.js";
import { renderShell, setPageHeader } from "./core/layout.js";

/**
 * Admin page runner. Each admin HTML page sets <body data-page="name">; this loads
 * admin/js/pages/<name>.js, which exports:
 *   title · crumbs? · perm? (permission needed) · default(ctx) -> html · init(root, ctx)?
 * ctx = { admin }. The login page renders without the shell.
 */
const page = document.body.dataset.page;
const root = document.getElementById("admin-root");
const admin = currentAdmin();

const notice = (icon, title, text) =>
  html`<div class="oga-dt__empty card py-5">
    <i class="fa-solid ${icon}"></i>
    <h2 class="h5">${title}</h2>
    <p class="mb-0">${text}</p>
  </div>`;

if (page === "login") {
  load(null);
} else if (!admin) {
  location.replace(`${ROOT}admin/login.html`);
} else {
  // Render the shell synchronously so AdminLTE can bind to it on DOMContentLoaded.
  renderShell(
    root,
    admin,
    `admin/${location.pathname.split("/").pop() || "index.html"}`,
  );
  document.getElementById("logoutBtn").addEventListener("click", async () => {
    if (await notify.confirm({ title: "Sign out?", confirmText: "Sign out" })) {
      logoutAdmin();
      location.assign(`${ROOT}admin/login.html`);
    }
  });
  load(admin);
}

async function load(who) {
  const target = who ? document.getElementById("page") : root;
  try {
    const mod = await import(`./pages/${page}.js`);
    document.title = `${mod.title ?? "Admin"} — OGA Admin`;
    if (who) setPageHeader(mod.title ?? "", mod.crumbs ?? [mod.title]);
    if (who && mod.perm && !can(who, mod.perm)) {
      mount(
        target,
        notice("fa-lock", "No access", "Your role doesn’t include this page."),
      );
      return;
    }
    mount(target, await mod.default({ admin: who }));
    await mod.init?.(target, { admin: who });
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
