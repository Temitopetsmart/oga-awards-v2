import { ROOT, APP } from "../../shared/js/config/app.config.js";
import { html, mount } from "../../shared/js/core/dom.js";
import { notify } from "../../shared/js/ui/notify.js";
import { normalizeMatric } from "../../shared/js/core/matric.js";
import { getHomeContext } from "../../shared/js/services/event.service.js";
import {
  confirmRegistration,
  saveMatric,
  currentNominee,
} from "../../shared/js/services/auth.service.js";
import { loadPortalData } from "./data.js";
import { renderShell, wireShell } from "./shell.js";
import { mountAdSlot } from "../../shared/js/components/oga-ads.js";

/**
 * The Nominee Portal runner. Each portal page sets <body data-page="name"> and portal/js/pages/<n>.js exports:
 *   title (string) · default(p) -> html · init(root, p) (optional)
 * where p carries everything the pages share (see data.js).
 */
const root = document.getElementById("portal-root");
const page = document.body.dataset.page;

const message = (icon, title, text, actions = []) =>
  html`<div class="pt-shell">
    <div class="pt-main">
      <div class="pt-content">
        <div class="pt-card pt-empty" style="max-width:34rem;margin:4rem auto">
          <i class="fa-solid ${icon}"></i>
          <h2 class="h4">${title}</h2>
          <p>${text}</p>
          <div class="d-flex gap-2 justify-content-center flex-wrap">
            ${actions.map(
              (a) =>
                html`<a class="btn btn-gold" href="${a.href}">${a.label}</a>`,
            )}
          </div>
        </div>
      </div>
    </div>
  </div>`;

/** An account made before matric numbers existed has to give one the next time it is opened. */
async function askForMatric() {
  const example = APP.matric?.formats?.find((f) => f.example)?.example ?? "";
  await notify.prompt({
    title: "Add your matric number",
    text: "Every student has one matric number on this platform. You only do this once.",
    placeholder: example,
    confirmText: "Save",
    dismissible: false,
    save: async (value) => {
      if (!normalizeMatric(value, APP.matric))
        throw new Error(
          `That is not a valid matric number${example ? ` (for example ${example})` : ""}.`,
        );
      await saveMatric(value);
    },
  });
  location.reload();
}

async function boot() {
  mount(
    root,
    html`<div class="container py-5" aria-busy="true">
      <div class="oga-skeleton mb-3" style="height:1.5rem;width:20%"></div>
      <div class="oga-skeleton" style="height:14rem"></div>
    </div>`,
  );
  try {
    if (APP.maintenance?.enabled) return location.replace(`${ROOT}maintenance`);
    const ctx = await getHomeContext();
    const params = new URLSearchParams(location.search);

    // Back from paying the registration fee: the server checks the payment before anything is created.
    if (params.get("pay") === "return" && params.get("tx_ref")) {
      const ref = params.get("tx_ref");
      const cancelled = params.get("status") === "cancelled";
      history.replaceState(null, "", location.pathname);
      if (cancelled)
        notify.toast("info", "Payment cancelled. You have not been charged.");
      else {
        try {
          await confirmRegistration(ref);
        } catch (err) {
          await notify.warning(
            "We couldn’t confirm your payment yet",
            err.message,
          );
        }
      }
    }

    const p = await loadPortalData(ctx, params);
    if (p.blocked === "signin") return location.replace(`${ROOT}login`);
    if (p.blocked === "admin")
      return mount(
        root,
        message(
          "fa-user-lock",
          "Admin sign-in needed",
          "Sign in to the admin to preview a nominee’s portal.",
          [{ label: "Admin sign-in", href: `${ROOT}admin/login` }],
        ),
      );
    if (p.blocked === "missing")
      return mount(
        root,
        message("fa-user-slash", "Nominee not found", "", [
          { label: "Back", href: ROOT },
        ]),
      );

    const mod = await import(`./pages/${page}.js`);
    const content = await mod.default(p);
    mount(root, renderShell({ page, title: mod.title, content, p }));
    document.title = `${mod.title} — Nominee Portal · ${ctx.occasion.name} ${ctx.occasion.edition}`;
    document.body.dataset.state = ctx.state;
    wireShell(root);
    await mod.init?.(root, p);
    // Sponsors for nominees. An admin previewing a portal (read-only) does not add to the counts.
    mountAdSlot(root.querySelector("#portalAdSlot"), "portal", {
      count: !p.readOnly,
    }).catch(() => {});
    if (!p.readOnly && currentNominee()?.needsMatric) askForMatric();
  } catch (err) {
    console.error(err);
    mount(
      root,
      message(
        "fa-triangle-exclamation",
        "We couldn’t load this page",
        "Please check your connection and try again.",
        [{ label: "Reload", href: location.href }],
      ),
    );
  }
}
boot();
