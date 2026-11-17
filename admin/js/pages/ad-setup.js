import { html, mount } from "../../../shared/js/core/dom.js";
import { money, number } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { DataTable } from "../components/data-table.js";
import { openCustomModal } from "../components/modal.js";
import {
  fetchSetup,
  saveAdSettings,
  saveCategory,
  deleteCategory,
} from "../services/ad.api.js";

export const title = "Ad setup";
export const perm = "ads.manage";
export const crumbs = ["Advertising", "Ad setup"];

const MEDIA = {
  text: "Text only",
  image: "Logo and banner",
  image_video: "Logo, banner and a video link",
};

export default async function adSetup() {
  return html`<div class="row g-3">
    <div class="col-xl-5">
      <form class="card" id="setForm" novalidate>
        <div class="card-header"><strong>Settings</strong></div>
        <div class="card-body" id="setBody">
          <div class="text-secondary">Loading…</div>
        </div>
        <div class="card-footer text-end">
          <button class="btn btn-gold" type="submit">Save settings</button>
        </div>
      </form>
    </div>
    <div class="col-xl-7">
      <div class="card">
        <div
          class="card-header d-flex justify-content-between align-items-center"
        >
          <strong>Packages advertisers can buy</strong
          ><button class="btn btn-gold btn-sm" id="addCat">
            <i class="fa-solid fa-plus me-1"></i>New package
          </button>
        </div>
        <div class="card-body">
          <p class="small text-secondary">
            A package sets the price, how long the ad runs, where it shows and
            what must be uploaded. Changing a package never changes ads already
            sold. Packages start switched off: set real prices, switch them on,
            then open orders in Settings.
          </p>
          <div id="catTable"></div>
        </div>
      </div>
    </div>
  </div>`;
}

export async function init(root, { can }) {
  let setup = await fetchSetup();
  const s0 = () => setup.settings;

  /* ---------------------------------------------------------------- settings and places */
  const form = root.querySelector("#setForm");
  const paint = () => {
    const s = s0();
    const num = (name, label, v, min, max, help = "") =>
      html`<div class="col-6">
        <label class="form-label small" for="s-${name}">${label}</label
        ><input
          class="form-control form-control-sm"
          id="s-${name}"
          name="${name}"
          type="number"
          min="${min}"
          max="${max}"
          value="${v}"
        />${help ? html`<div class="form-text">${help}</div>` : ""}
      </div>`;
    mount(
      root.querySelector("#setBody"),
      html`<div class="form-check form-switch mb-2">
          <input
            class="form-check-input"
            type="checkbox"
            role="switch"
            id="s-enabled"
            name="enabled"
            ${s.enabled ? "checked" : ""}
          /><label class="form-check-label" for="s-enabled"
            >Show ads on the site (master switch)</label
          >
        </div>
        <div class="form-check form-switch mb-3">
          <input
            class="form-check-input"
            type="checkbox"
            role="switch"
            id="s-open"
            name="submissionsOpen"
            ${s.submissionsOpen ? "checked" : ""}
          /><label class="form-check-label" for="s-open"
            >Accept new ad orders on the Advertise page</label
          >
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label small" for="s-label"
              >Label shown on every ad</label
            ><input
              class="form-control form-control-sm"
              id="s-label"
              name="label"
              maxlength="20"
              value="${s.label}"
            />
          </div>
          <div class="col-6">
            <label class="form-label small" for="s-cta"
              >Default button text</label
            ><input
              class="form-control form-control-sm"
              id="s-cta"
              name="defaultCta"
              maxlength="30"
              value="${s.defaultCta}"
            />
          </div>
          ${num(
            "expiryWarningDays",
            "Warn advertisers this many days before the end",
            s.expiryWarningDays,
            0,
            60,
            "0 = never warn",
          )}
          ${num(
            "maxDurationDays",
            "Longest any ad may run (days)",
            s.maxDurationDays,
            1,
            3650,
          )}
          ${num(
            "maxPendingPerEmail",
            "Open orders allowed per email",
            s.maxPendingPerEmail,
            1,
            50,
          )}
        </div>
        <label class="form-label small" for="s-intro"
          >Introduction on the Advertise page</label
        >
        <textarea
          class="form-control form-control-sm mb-2"
          id="s-intro"
          name="intro"
          rows="3"
          maxlength="600"
        >
${s.intro}</textarea
        >
        <label class="form-label small" for="s-terms"
          >Terms the advertiser must accept (empty = no tick box)</label
        >
        <textarea
          class="form-control form-control-sm mb-3"
          id="s-terms"
          name="terms"
          rows="3"
          maxlength="2000"
        >
${s.terms}</textarea
        >
        <h3 class="h6 text-uppercase text-secondary">Places</h3>
        ${setup.placements.map((k) => {
          const p = s.placements[k];
          return html`<fieldset
            class="border rounded p-2 mb-2"
            data-place="${k}"
          >
            <legend class="visually-hidden">${p.label}</legend>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <input
                class="form-control form-control-sm me-2"
                name="label"
                maxlength="80"
                value="${p.label}"
                aria-label="Name of this place"
              />
              <div class="form-check form-switch mb-0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  name="enabled"
                  ${p.enabled ? "checked" : ""}
                  aria-label="Show ads here"
                />
              </div>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small mb-0">Seconds per ad</label
                ><input
                  class="form-control form-control-sm"
                  name="rotateSeconds"
                  type="number"
                  min="3"
                  max="120"
                  value="${p.rotateSeconds}"
                />
              </div>
              <div class="col-6">
                <label class="form-label small mb-0">Most ads in rotation</label
                ><input
                  class="form-control form-control-sm"
                  name="maxAds"
                  type="number"
                  min="1"
                  max="20"
                  value="${p.maxAds}"
                />
              </div>
            </div>
          </fieldset>`;
        })}
        <p class="form-text mb-0">
          Home page banner and every-page strip are placed by the site; the
          portal strip sits at the bottom of every nominee portal page.
        </p>`,
    );
  };
  paint();
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const f = form.elements;
    const value = {
      enabled: f.enabled.checked,
      submissionsOpen: f.submissionsOpen.checked,
      label: f.label.value,
      defaultCta: f.defaultCta.value,
      intro: f.intro.value,
      terms: f.terms.value,
      expiryWarningDays: f.expiryWarningDays.value,
      maxDurationDays: f.maxDurationDays.value,
      maxPendingPerEmail: f.maxPendingPerEmail.value,
      placements: {},
    };
    // the first "label" element is the ad label; the ones inside fieldsets belong to the places
    value.label = form.querySelector("#s-label").value;
    form.querySelectorAll("[data-place]").forEach((fs) => {
      value.placements[fs.dataset.place] = {
        label: fs.querySelector('[name="label"]').value,
        enabled: fs.querySelector('[name="enabled"]').checked,
        rotateSeconds: fs.querySelector('[name="rotateSeconds"]').value,
        maxAds: fs.querySelector('[name="maxAds"]').value,
      };
    });
    try {
      await saveAdSettings(value);
      setup = await fetchSetup();
      paint();
      notify.toast("success", "Settings saved.");
    } catch (err) {
      notify.failure(err);
    }
  });

  /* ---------------------------------------------------------------- packages */
  const rows = () =>
    setup.categories.map((c) => ({ ...c, state: c.active ? "on" : "off" }));
  const table = new DataTable(root.querySelector("#catTable"), {
    can,
    rows: rows(),
    searchKeys: ["name"],
    columns: [
      {
        key: "name",
        label: "Package",
        sort: true,
        render: (c) =>
          html`<strong>${c.name}</strong
            ><small class="d-block text-secondary">${c.description}</small>`,
      },
      {
        key: "price",
        label: "Price",
        sort: true,
        render: (c) => (c.price > 0 ? money(c.price) : "Free"),
      },
      { key: "durationDays", label: "Days", sort: true },
      {
        key: "placements",
        label: "Shows in",
        render: (c) =>
          c.placements
            .map((p) => setup.settings.placements[p]?.label ?? p)
            .join(", "),
      },
      { key: "mediaMode", label: "Upload", render: (c) => MEDIA[c.mediaMode] },
      { key: "used", label: "Sold", render: (c) => number(c.used) },
      {
        key: "state",
        label: "Status",
        render: (c) =>
          c.active
            ? html`<span class="badge text-bg-success">On</span>`
            : html`<span class="badge text-bg-secondary">Off</span>`,
      },
    ],
    actions: [
      { key: "edit", label: "Edit", icon: "fa-pen" },
      { key: "delete", label: "Delete", icon: "fa-trash" },
    ],
    empty: "No packages yet.",
    onAction: (k, c) => (k === "edit" ? pkgForm(c) : removePkg(c)),
  });
  const reload = async () => {
    setup = await fetchSetup();
    table.setRows(rows());
    paint();
  };
  root.querySelector("#addCat").addEventListener("click", () => pkgForm(null));

  async function removePkg(c) {
    if (c.used > 0)
      return notify.info(
        "It has been sold",
        "Ads have been sold in this package, so it cannot be deleted. Edit it and switch it off instead.",
      );
    if (
      !(await notify.confirm({
        title: `Delete “${c.name}”?`,
        danger: true,
        confirmText: "Delete",
      }))
    )
      return;
    try {
      await deleteCategory(c.id);
      await reload();
    } catch (err) {
      notify.failure(err);
    }
  }

  function pkgForm(c) {
    const v = c ?? {
      name: "",
      description: "",
      price: 0,
      durationDays: 30,
      placements: ["site_footer"],
      mediaMode: "image",
      requireLogo: false,
      requireBanner: false,
      maxImageKb: 2048,
      maxHeadline: 70,
      maxText: 160,
      maxRunning: 0,
      active: true,
      sortOrder: 0,
    };
    const n = (name, label, val, min, max, step = 1) =>
      html`<div class="col-6 col-md-4">
        <label class="form-label small" for="pk-${name}">${label}</label
        ><input
          class="form-control form-control-sm"
          id="pk-${name}"
          name="${name}"
          type="number"
          min="${min}"
          max="${max}"
          step="${step}"
          value="${val}"
        />
      </div>`;
    openCustomModal({
      title: c ? `Edit “${c.name}”` : "New package",
      body: html`<div class="row g-3">
        <div class="col-md-8">
          <label class="form-label small" for="pk-name">Name</label
          ><input
            class="form-control"
            id="pk-name"
            name="name"
            maxlength="80"
            value="${v.name}"
          />
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <div class="form-check form-switch">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="pk-active"
              name="active"
              ${v.active ? "checked" : ""}
            /><label class="form-check-label" for="pk-active">On sale</label>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label small" for="pk-desc"
            >Short description shown to advertisers</label
          ><input
            class="form-control"
            id="pk-desc"
            name="description"
            maxlength="500"
            value="${v.description}"
          />
        </div>
        ${n("price", "Price (0 = free)", v.price, 0, 100000000, 0.01)}${n(
          "durationDays",
          "Runs for (days)",
          v.durationDays,
          1,
          setup.settings.maxDurationDays,
        )}${n("sortOrder", "Order on the form", v.sortOrder, -1000, 1000)}
        <div class="col-md-6">
          <label class="form-label small" for="pk-media"
            >What can the advertiser upload?</label
          ><select
            class="form-select form-select-sm"
            id="pk-media"
            name="mediaMode"
          >
            ${Object.entries(MEDIA).map(
              ([k, l]) =>
                html`<option
                  value="${k}"
                  ${v.mediaMode === k ? "selected" : ""}
                >
                  ${l}
                </option>`,
            )}
          </select>
        </div>
        <div class="col-md-6 d-flex gap-3 align-items-end">
          <div class="form-check">
            <input
              class="form-check-input"
              type="checkbox"
              id="pk-rl"
              name="requireLogo"
              ${v.requireLogo ? "checked" : ""}
            /><label class="form-check-label small" for="pk-rl"
              >Logo required</label
            >
          </div>
          <div class="form-check">
            <input
              class="form-check-input"
              type="checkbox"
              id="pk-rb"
              name="requireBanner"
              ${v.requireBanner ? "checked" : ""}
            /><label class="form-check-label small" for="pk-rb"
              >Banner required</label
            >
          </div>
        </div>
        ${n("maxImageKb", "Largest image (KB)", v.maxImageKb, 50, 5120)}${n(
          "maxHeadline",
          "Headline length",
          v.maxHeadline,
          10,
          120,
        )}${n("maxText", "Description length", v.maxText, 0, 500)}
        ${n(
          "maxRunning",
          "Most running at once (0 = no limit)",
          v.maxRunning,
          0,
          1000,
        )}
        <div class="col-12">
          <div class="form-label small">Where ads of this package show</div>
          ${setup.placements.map(
            (k) =>
              html`<div class="form-check form-check-inline">
                <input
                  class="form-check-input"
                  type="checkbox"
                  name="place"
                  value="${k}"
                  id="pk-pl-${k}"
                  ${v.placements.includes(k) ? "checked" : ""}
                /><label class="form-check-label small" for="pk-pl-${k}"
                  >${setup.settings.placements[k].label}</label
                >
              </div>`,
          )}
        </div>
      </div>`,
      onSubmit: async (f) => {
        const e = f.elements;
        await saveCategory(c?.id ?? null, {
          name: e.name.value,
          description: e.description.value,
          price: e.price.value,
          durationDays: e.durationDays.value,
          sortOrder: e.sortOrder.value,
          mediaMode: e.mediaMode.value,
          requireLogo: e.requireLogo.checked,
          requireBanner: e.requireBanner.checked,
          maxImageKb: e.maxImageKb.value,
          maxHeadline: e.maxHeadline.value,
          maxText: e.maxText.value,
          maxRunning: e.maxRunning.value,
          active: e.active.checked,
          placements: [
            ...f.querySelectorAll('input[name="place"]:checked'),
          ].map((x) => x.value),
        });
        await reload();
        notify.toast("success", "Package saved.");
      },
    });
  }
}
