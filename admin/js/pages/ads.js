import { html, mount, esc } from "../../../shared/js/core/dom.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { money, number } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { adPreviewHtml } from "../../../shared/js/components/oga-ads.js";
import { DataTable } from "../components/data-table.js";
import { openCustomModal, openInfoModal } from "../components/modal.js";
import {
  fetchAds,
  fetchAd,
  fetchSetup,
  fetchReport,
  approveAd,
  rejectAd,
  removeAd,
  extendAd,
  markAdPaid,
  markRefunded,
  editAd,
  changeMedia,
  createAd,
} from "../services/ad.api.js";

export const title = "Ads";
export const perm = "ads.view";
export const crumbs = ["Advertising", "Ads"];

const TABS = [
  { key: "inbox", label: "To review", status: "inbox" },
  { key: "running", label: "Running", status: "running" },
  { key: "scheduled", label: "Scheduled", status: "scheduled" },
  { key: "refund", label: "Refunds due", status: "refund" },
  { key: "all", label: "All ads", status: "" },
];
const PHASE = {
  live: ["success", "Live"],
  scheduled: ["info", "Scheduled"],
  paid: ["warning", "To review"],
  awaiting_payment: ["secondary", "Awaiting payment"],
  draft: ["secondary", "Not paid"],
  expired: ["secondary", "Ended"],
  rejected: ["danger", "Rejected"],
  removed: ["danger", "Removed"],
  approved: ["success", "Approved"],
};
const chip = (ad) => {
  const [tone, text] = PHASE[ad.phase] ?? ["secondary", ad.statusLabel];
  return html`<span class="badge text-bg-${tone}">${text}</span>`;
};
const day = (s) =>
  s
    ? new Date(`${s.slice(0, 10)}T12:00:00`).toLocaleDateString(undefined, {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : "";
const how = { online: "online", manual: "cash/transfer", free: "free" };

export default async function ads() {
  return html`<div class="card">
    <div
      class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"
    >
      <ul class="nav nav-tabs card-header-tabs" role="tablist" id="adTabs">
        ${TABS.map(
          (t, i) =>
            html`<li class="nav-item">
              <button
                class="nav-link ${i === 0 ? "active" : ""}"
                data-bs-toggle="tab"
                data-bs-target="#pane-${t.key}"
                type="button"
                role="tab"
                data-key="${t.key}"
              >
                ${t.label}
                <span
                  class="badge text-bg-secondary ms-1"
                  data-count="${t.key}"
                ></span>
              </button>
            </li>`,
        )}
        <li class="nav-item">
          <button
            class="nav-link"
            data-bs-toggle="tab"
            data-bs-target="#pane-report"
            type="button"
            role="tab"
            data-key="report"
          >
            Report
          </button>
        </li>
      </ul>
      <button class="btn btn-gold btn-sm d-none" id="addAd">
        <i class="fa-solid fa-plus me-1"></i>Add a free or cash ad
      </button>
    </div>
    <div class="tab-content">
      ${TABS.map(
        (t, i) =>
          html`<div
            class="tab-pane fade ${i === 0 ? "show active" : ""} p-3"
            id="pane-${t.key}"
            role="tabpanel"
          >
            <div data-table="${t.key}"></div>
          </div>`,
      )}
      <div class="tab-pane fade p-3" id="pane-report" role="tabpanel">
        <div id="reportBox"></div>
      </div>
    </div>
  </div>`;
}

export async function init(root, { can }) {
  const setup = await fetchSetup();
  const places = Object.fromEntries(
    Object.entries(setup.settings.placements).map(([k, v]) => [k, v.label]),
  );
  const tables = new Map();
  let counts = {};
  const paintCounts = () =>
    root.querySelectorAll("[data-count]").forEach((b) => {
      const n = counts[b.dataset.count];
      b.textContent = n ? number(n) : "";
      b.classList.toggle(
        "text-bg-warning",
        ["inbox", "refund"].includes(b.dataset.count) && n > 0,
      );
    });
  const refresh = () => tables.forEach((t) => t.reload());

  const columns = [
    {
      key: "headline",
      label: "Ad",
      render: (a) =>
        html`<div class="d-flex align-items-center gap-2">
          ${a.logo || a.banner
            ? html`<img
                src="${mediaUrl(a.logo || a.banner)}"
                alt=""
                width="40"
                height="40"
                style="object-fit:contain;border-radius:6px;background:#111"
              />`
            : ""}
          <div>
            <strong>${a.headline}</strong
            ><small class="d-block text-secondary"
              >${a.business} · ${a.reference}</small
            >
          </div>
        </div>`,
    },
    {
      key: "category",
      label: "Package",
      render: (a) =>
        html`${a.category}<small class="d-block text-secondary"
            >${a.source === "admin" ? "added by admin" : "online order"}</small
          >`,
    },
    { key: "phase", label: "Status", render: (a) => chip(a) },
    {
      key: "endsAt",
      label: "Runs",
      render: (a) =>
        a.startsAt
          ? html`${day(a.startsAt)} to ${day(a.endsAt)}`
          : html`<span class="text-secondary"
              >${a.durationDays} days once approved</span
            >`,
    },
    {
      key: "amount",
      label: "Money",
      render: (a) =>
        a.amount > 0
          ? html`${money(a.amount)}<small class="d-block text-secondary"
                >${a.paidVia ? how[a.paidVia] : "not paid"}${a.refundedAt
                  ? " · refunded"
                  : ""}</small
              >`
          : html`<span class="text-secondary">Free</span>`,
    },
    {
      key: "impressions",
      label: "Shown / clicks",
      render: (a) => `${number(a.impressions)} / ${number(a.clicks)}`,
    },
  ];
  const makeTable = (key) => {
    const t = TABS.find((x) => x.key === key);
    const table = new DataTable(root.querySelector(`[data-table="${key}"]`), {
      can,
      columns,
      searchKeys: [],
      pageSize: 15,
      server: async (p) => {
        const r = await fetchAds({
          status: t.status === "" ? (p.filters.status ?? "") : t.status,
          q: p.q,
          page: p.page,
          perPage: p.perPage,
        });
        counts = r.counts;
        paintCounts();
        return { rows: r.rows, total: r.total, page: r.page };
      },
      filters:
        key === "all"
          ? [
              {
                key: "status",
                label: "Status",
                options: [
                  "draft",
                  "awaiting_payment",
                  "paid",
                  "approved",
                  "expired",
                  "rejected",
                  "removed",
                ].map((v) => ({ value: v, label: PHASE[v]?.[1] ?? v })),
              },
            ]
          : [],
      actions: [
        {
          key: "open",
          label: key === "inbox" ? "Review" : "Open",
          icon: "fa-eye",
        },
      ],
      empty:
        key === "inbox" ? "Nothing is waiting for review." : "Nothing here.",
      onAction: (_k, a) => openAd(a.id),
    });
    tables.set(key, table);
  };
  makeTable("inbox");
  root.querySelector("#adTabs").addEventListener("shown.bs.tab", (e) => {
    const key = e.target.dataset.key;
    if (key === "report") return reportView();
    if (!tables.has(key)) makeTable(key);
  });

  if (can("ads.manage")) {
    const add = root.querySelector("#addAd");
    add.classList.remove("d-none");
    add.addEventListener("click", () => adForm());
  }

  /* ---------------------------------------------------------------- one ad */
  async function openAd(id) {
    let ad;
    try {
      ad = (await fetchAd(id)).ad;
    } catch (err) {
      return notify.failure(err);
    }
    const stats = {};
    for (const s of ad.stats) {
      stats[s.placement] ??= { i: 0, c: 0 };
      stats[s.placement].i += s.impressions;
      stats[s.placement].c += s.clicks;
    }
    const review = can("ads.review");
    const manage = can("ads.manage");
    const btn = (k, label, cls = "btn-ghost", icon = "") =>
      html`<button type="button" class="btn ${cls} btn-sm" data-act="${k}">
        ${icon ? html`<i class="fa-solid ${icon} me-1"></i>` : ""}${label}
      </button>`;
    const buttons = [];
    if (review && ad.status === "paid")
      buttons.push(
        btn("approve", "Approve", "btn-gold", "fa-check"),
        btn("reject", "Reject", "btn-ghost", "fa-xmark"),
      );
    if (review && ["awaiting_payment", "draft"].includes(ad.status))
      buttons.push(btn("reject", "Reject", "btn-ghost", "fa-xmark"));
    if (manage && ["awaiting_payment", "draft"].includes(ad.status))
      buttons.push(
        btn(
          "paid",
          "Mark as paid (cash/transfer)",
          "btn-gold",
          "fa-hand-holding-dollar",
        ),
      );
    if (manage && ad.status === "approved" && ad.phase !== "expired")
      buttons.push(btn("extend", "Extend", "btn-ghost", "fa-calendar-plus"));
    if (manage && ad.status !== "removed" && ad.status !== "rejected")
      buttons.push(
        btn("remove", "Remove", "btn-ghost text-danger", "fa-trash"),
      );
    if (manage && ad.refundDue)
      buttons.push(
        btn("refund", "Record refund", "btn-gold", "fa-rotate-left"),
      );
    if (review && ad.status !== "removed")
      buttons.push(
        btn("edit", "Edit wording or pictures", "btn-ghost", "fa-pen"),
      );
    const body = html` ${adPreviewHtml(ad)}
      <div class="row g-3 small">
        <div class="col-md-6">
          <h3 class="h6 text-uppercase text-secondary">Advertiser</h3>
          <div><strong>${ad.business}</strong></div>
          <div>
            ${ad.contact}
            ${ad.email
              ? html`· <a href="mailto:${ad.email}">${ad.email}</a>`
              : ""}
            ${ad.phone ? html`· ${ad.phone}` : ""}
          </div>
          <div class="text-break">
            Link:
            <a href="${ad.link}" target="_blank" rel="noopener noreferrer"
              >${ad.link}</a
            >
          </div>
          ${ad.videoUrl
            ? html`<div class="text-break">Video: ${ad.videoUrl}</div>`
            : ""}
        </div>
        <div class="col-md-6">
          <h3 class="h6 text-uppercase text-secondary">Order</h3>
          <div>${chip(ad)} ${ad.reference}</div>
          <div>
            ${ad.category} · ${ad.durationDays} days ·
            ${ad.amount > 0 ? money(ad.amount) : "Free"}${ad.paidVia
              ? ` (${how[ad.paidVia]})`
              : ""}
          </div>
          ${ad.paidNote ? html`<div>Note: ${ad.paidNote}</div>` : ""}
          ${ad.payment
            ? html`<div>
                Payment ${ad.payment.reference}: ${ad.payment.status}
              </div>`
            : ""}
          <div>
            Shows in:
            ${ad.placements.map((p) => places[p] ?? p).join(", ") || "nowhere"}
          </div>
          ${ad.startsAt
            ? html`<div>
                Runs ${day(ad.startsAt)} to
                ${day(ad.endsAt)}${ad.reviewer
                  ? ` · reviewed by ${ad.reviewer}`
                  : ""}
              </div>`
            : ""}
          ${ad.reviewNote
            ? html`<div class="text-warning">
                Reviewer note: ${ad.reviewNote}
              </div>`
            : ""}
          ${ad.refundDue
            ? html`<div class="text-warning">
                A refund of ${money(ad.amount)} is still owed.
              </div>`
            : ""}
          ${ad.refundedAt
            ? html`<div>Refunded ${day(ad.refundedAt)}: ${ad.refundNote}</div>`
            : ""}
        </div>
        <div class="col-12">
          <h3 class="h6 text-uppercase text-secondary">
            Last 30 days (totals only, no visitor data)
          </h3>
          ${Object.keys(stats).length
            ? html`<table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Place</th>
                    <th class="text-end">Shown</th>
                    <th class="text-end">Clicks</th>
                  </tr>
                </thead>
                <tbody>
                  ${Object.entries(stats).map(
                    ([p, v]) =>
                      html`<tr>
                        <td>${places[p] ?? p}</td>
                        <td class="text-end">${number(v.i)}</td>
                        <td class="text-end">${number(v.c)}</td>
                      </tr>`,
                  )}
                </tbody>
              </table>`
            : html`<span class="text-secondary"
                >No showings yet. All time: ${number(ad.impressions)} shown,
                ${number(ad.clicks)} clicks.</span
              >`}
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3" id="adActions">${buttons}</div>`;
    openInfoModal({
      title: ad.headline,
      body,
      size: "modal-lg",
      onOpen: (el, modal) => {
        const done = () => {
          modal.hide();
          refresh();
        };
        el.querySelector("#adActions")?.addEventListener("click", async (e) => {
          const act = e.target.closest("[data-act]")?.dataset.act;
          if (!act) return;
          try {
            if (act === "approve") {
              modal.hide();
              approveForm(ad);
            } else if (act === "edit") {
              modal.hide();
              editForm(ad);
            } else if (act === "reject") {
              if (
                await notify.prompt({
                  title: "Reject this ad",
                  text: "Write the reason. The advertiser will receive it by email.",
                  confirmText: "Reject",
                  autocapitalize: "sentences",
                  save: async (v) => rejectAd(ad.id, v),
                })
              )
                done();
            } else if (act === "remove") {
              if (
                await notify.prompt({
                  title: "Remove this ad",
                  text: "It stops showing at once. Write the reason (kept for the record).",
                  confirmText: "Remove",
                  autocapitalize: "sentences",
                  save: async (v) => removeAd(ad.id, v),
                })
              )
                done();
            } else if (act === "extend") {
              if (
                await notify.prompt({
                  title: "Extend this ad",
                  text: "How many days to add? (No ad may run longer than the limit in Ad setup.)",
                  placeholder: "7",
                  confirmText: "Extend",
                  autocapitalize: "none",
                  save: async (v) => extendAd(ad.id, Number(v)),
                })
              )
                done();
            } else if (act === "paid") {
              if (
                await notify.prompt({
                  title: "Mark as paid",
                  text: "How was it paid and who received it? The online payment link is cancelled.",
                  confirmText: "Mark as paid",
                  autocapitalize: "sentences",
                  save: async (v) => markAdPaid(ad.id, v),
                })
              )
                done();
            } else if (act === "refund") {
              if (
                await notify.prompt({
                  title: "Record the refund",
                  text: "How was the money returned?",
                  confirmText: "Save",
                  autocapitalize: "sentences",
                  save: async (v) => markRefunded(ad.id, v),
                })
              )
                done();
            }
          } catch (err) {
            notify.failure(err);
          }
        });
      },
    });
  }

  const placeBoxes = (selected) =>
    Object.entries(places).map(
      ([k, label]) =>
        html`<div class="form-check">
          <input
            class="form-check-input"
            type="checkbox"
            name="place"
            value="${k}"
            id="pl-${k}"
            ${selected.includes(k) ? "checked" : ""}
          /><label class="form-check-label" for="pl-${k}">${label}</label>
        </div>`,
    );
  const checked = (form, name) =>
    [...form.querySelectorAll(`input[name="${name}"]:checked`)].map(
      (x) => x.value,
    );

  function approveForm(ad) {
    openCustomModal({
      title: `Approve: ${ad.headline}`,
      size: "modal-md",
      submitLabel: "Approve and publish",
      body: html`<p class="small text-secondary">
          It will run for <strong>${ad.durationDays} days</strong> from the
          start date, then stop on its own.
        </p>
        <label class="form-label" for="apStart"
          >Start date (leave empty to start now)</label
        >
        <input
          class="form-control mb-3"
          id="apStart"
          name="start"
          type="date"
        />
        <div class="form-label">Where it shows</div>
        ${placeBoxes(ad.placements)}`,
      onSubmit: async (form) => {
        const places = checked(form, "place");
        await approveAd(ad.id, {
          startsAt: form.elements.start.value || null,
          placements: places,
        });
        notify.toast("success", "Approved. The advertiser has been emailed.");
        refresh();
      },
    });
  }

  function editForm(ad) {
    const input = (name, label, value, type = "text") =>
      html`<div class="col-md-6">
        <label class="form-label" for="ed-${name}">${label}</label
        ><input
          class="form-control"
          id="ed-${name}"
          name="${name}"
          type="${type}"
          value="${value ?? ""}"
        />
      </div>`;
    openCustomModal({
      title: `Edit: ${ad.headline}`,
      body: html`<div class="row g-3">
        ${input("business_name", "Business name", ad.business)}${input(
          "contact_name",
          "Contact name",
          ad.contact,
        )}
        ${input("email", "Email", ad.email, "email")}${input(
          "phone",
          "Phone",
          ad.phone,
        )}
        <div class="col-12">
          <label class="form-label" for="ed-headline">Headline</label
          ><input
            class="form-control"
            id="ed-headline"
            name="headline"
            value="${ad.headline}"
          />
        </div>
        <div class="col-12">
          <label class="form-label" for="ed-body">Description</label
          ><textarea class="form-control" id="ed-body" name="body" rows="3">
${ad.text}</textarea
          >
        </div>
        ${input("link_url", "Link (https://)", ad.link, "url")}${input(
          "cta_label",
          "Button text",
          ad.cta,
        )}
        <div class="col-12">
          <label class="form-label" for="ed-video"
            >Video link (YouTube or Vimeo)</label
          ><input
            class="form-control"
            id="ed-video"
            name="video_url"
            value="${ad.videoUrl ?? ""}"
          />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="ed-logo">Replace the logo</label
          ><input
            class="form-control"
            id="ed-logo"
            name="logo"
            type="file"
            accept="image/png,image/jpeg,image/webp"
          />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="ed-banner">Replace the banner</label
          ><input
            class="form-control"
            id="ed-banner"
            name="banner"
            type="file"
            accept="image/png,image/jpeg,image/webp"
          />
        </div>
        <div class="col-12">
          <div class="form-label">Where it shows</div>
          ${placeBoxes(ad.placements)}
        </div>
      </div>`,
      onSubmit: async (form) => {
        const v = (n) => form.elements[n].value;
        await editAd(ad.id, {
          business_name: v("business_name"),
          contact_name: v("contact_name"),
          email: v("email"),
          phone: v("phone"),
          headline: v("headline"),
          body: v("body"),
          link_url: v("link_url"),
          cta_label: v("cta_label"),
          video_url: v("video_url"),
          placements: checked(form, "place"),
        });
        const logo = form.elements.logo.files[0];
        const banner = form.elements.banner.files[0];
        if (logo || banner) await changeMedia(ad.id, {}, { logo, banner });
        notify.toast("success", "Saved.");
        refresh();
      },
    });
  }

  async function adForm() {
    const cats = setup.categories;
    openCustomModal({
      title: "Add a free or cash ad",
      submitLabel: "Add and publish",
      body: html`<p class="small text-secondary">
          This skips review and starts at once (or on the date you pick). Every
          ad ends; choose how many days.
        </p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="ad-cat"
              >Package (sets the rules)</label
            ><select class="form-select" id="ad-cat" name="category_id">
              ${cats.map(
                (c) =>
                  html`<option value="${c.id}" data-days="${c.durationDays}">
                    ${c.name}${c.active ? "" : " (off)"}
                  </option>`,
              )}
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="ad-biz">Business name</label
            ><input class="form-control" id="ad-biz" name="business_name" />
          </div>
          <div class="col-md-6">
            <label class="form-label" for="ad-email"
              >Email (optional, for notices)</label
            ><input
              class="form-control"
              id="ad-email"
              name="email"
              type="email"
            />
          </div>
          <div class="col-md-6">
            <label class="form-label" for="ad-contact"
              >Contact name (optional)</label
            ><input class="form-control" id="ad-contact" name="contact_name" />
          </div>
          <div class="col-12">
            <label class="form-label" for="ad-head">Headline</label
            ><input class="form-control" id="ad-head" name="headline" />
          </div>
          <div class="col-12">
            <label class="form-label" for="ad-body">Description</label
            ><textarea
              class="form-control"
              id="ad-body"
              name="body"
              rows="2"
            ></textarea>
          </div>
          <div class="col-md-8">
            <label class="form-label" for="ad-link">Link (https://)</label
            ><input
              class="form-control"
              id="ad-link"
              name="link_url"
              type="url"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label" for="ad-cta">Button text</label
            ><input class="form-control" id="ad-cta" name="cta_label" />
          </div>
          <div class="col-12">
            <label class="form-label" for="ad-video"
              >Video link (YouTube or Vimeo, optional)</label
            ><input class="form-control" id="ad-video" name="video_url" />
          </div>
          <div class="col-md-6">
            <label class="form-label" for="ad-logo">Logo</label
            ><input
              class="form-control"
              id="ad-logo"
              name="logo"
              type="file"
              accept="image/png,image/jpeg,image/webp"
            />
          </div>
          <div class="col-md-6">
            <label class="form-label" for="ad-banner">Banner</label
            ><input
              class="form-control"
              id="ad-banner"
              name="banner"
              type="file"
              accept="image/png,image/jpeg,image/webp"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label" for="ad-days">Runs for (days)</label
            ><input
              class="form-control"
              id="ad-days"
              name="duration_days"
              type="number"
              min="1"
              value="${cats[0]?.durationDays ?? 30}"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label" for="ad-start">Starts (empty = now)</label
            ><input
              class="form-control"
              id="ad-start"
              name="starts_at"
              type="date"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label" for="ad-deal">Deal</label
            ><select class="form-select" id="ad-deal" name="deal">
              <option value="free">Free ad</option>
              <option value="cash">Paid in cash or transfer</option>
            </select>
          </div>
          <div class="col-md-6 d-none" data-cash>
            <label class="form-label" for="ad-amount">Amount received</label
            ><input
              class="form-control"
              id="ad-amount"
              name="amount"
              type="number"
              min="0"
              step="0.01"
            />
          </div>
          <div class="col-md-6 d-none" data-cash>
            <label class="form-label" for="ad-note"
              >Note (who received it)</label
            ><input class="form-control" id="ad-note" name="note" />
          </div>
          <div class="col-12">
            <div class="form-label">Where it shows</div>
            ${placeBoxes(cats[0]?.placements ?? [])}
          </div>
        </div>`,
      onOpen: (form) => {
        form.elements.deal.addEventListener("change", () =>
          form
            .querySelectorAll("[data-cash]")
            .forEach((x) =>
              x.classList.toggle("d-none", form.elements.deal.value !== "cash"),
            ),
        );
        form.elements.category_id.addEventListener("change", () => {
          const c = cats.find((x) => x.id === form.elements.category_id.value);
          form.elements.duration_days.value = c.durationDays;
          form
            .querySelectorAll('input[name="place"]')
            .forEach((b) => (b.checked = c.placements.includes(b.value)));
        });
      },
      onSubmit: async (form) => {
        const v = (n) => form.elements[n].value;
        const fields = {
          category_id: v("category_id"),
          business_name: v("business_name"),
          email: v("email"),
          contact_name: v("contact_name"),
          headline: v("headline"),
          body: v("body"),
          link_url: v("link_url"),
          cta_label: v("cta_label"),
          video_url: v("video_url"),
          duration_days: v("duration_days"),
          starts_at: v("starts_at"),
          deal: v("deal"),
          amount: v("amount"),
          note: v("note"),
          placements: checked(form, "place"),
        };
        await createAd(fields, {
          logo: form.elements.logo.files[0],
          banner: form.elements.banner.files[0],
        });
        notify.toast("success", "Ad added and running.");
        refresh();
      },
    });
  }

  /* ---------------------------------------------------------------- report */
  let reportReady = false;
  function reportView() {
    if (reportReady) return;
    reportReady = true;
    const box = root.querySelector("#reportBox");
    const today = new Date().toISOString().slice(0, 10);
    const ago = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);
    mount(
      box,
      html`<form class="row g-2 align-items-end mb-3" id="repForm">
          <div class="col-auto">
            <label class="form-label small mb-0" for="repFrom">From</label
            ><input
              class="form-control form-control-sm"
              type="date"
              id="repFrom"
              value="${ago}"
            />
          </div>
          <div class="col-auto">
            <label class="form-label small mb-0" for="repTo">To</label
            ><input
              class="form-control form-control-sm"
              type="date"
              id="repTo"
              value="${today}"
            />
          </div>
          <div class="col-auto">
            <button class="btn btn-gold btn-sm" type="submit">
              Show report
            </button>
          </div>
        </form>
        <div id="repOut"></div>`,
    );
    const run = async () => {
      const out = box.querySelector("#repOut");
      mount(out, html`<div class="text-secondary">Loading…</div>`);
      try {
        const r = (
          await fetchReport(
            box.querySelector("#repFrom").value,
            box.querySelector("#repTo").value,
          )
        ).report;
        const max = Math.max(1, ...r.days.map((d) => d.impressions));
        mount(
          out,
          html`<div class="row g-3 mb-3">
              ${[
                ["Times shown", number(r.impressions)],
                ["Clicks", number(r.clicks)],
                ["Click rate", `${r.ctr}%`],
                ["Ad revenue (not refunded)", money(r.revenue.total)],
                [
                  "Refunds still owed",
                  `${r.refundsDue.count} (${money(r.refundsDue.total)})`,
                ],
              ].map(
                ([l, v]) =>
                  html`<div class="col-6 col-lg">
                    <div class="border rounded p-2 h-100">
                      <div class="text-secondary small">${l}</div>
                      <strong class="fs-5">${v}</strong>
                    </div>
                  </div>`,
              )}
            </div>
            <div class="row g-4">
              <div class="col-lg-6">
                <h3 class="h6 text-uppercase text-secondary">By place</h3>
                <table class="table table-sm">
                  <thead>
                    <tr>
                      <th>Place</th>
                      <th class="text-end">Shown</th>
                      <th class="text-end">Clicks</th>
                      <th class="text-end">Rate</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${r.placements.map(
                      (p) =>
                        html`<tr>
                          <td>${p.label}</td>
                          <td class="text-end">${number(p.impressions)}</td>
                          <td class="text-end">${number(p.clicks)}</td>
                          <td class="text-end">${p.ctr}%</td>
                        </tr>`,
                    )}
                  </tbody>
                </table>
                <h3 class="h6 text-uppercase text-secondary mt-3">
                  Revenue by package
                </h3>
                <table class="table table-sm">
                  <thead>
                    <tr>
                      <th>Package</th>
                      <th class="text-end">Ads</th>
                      <th class="text-end">Received</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${r.revenue.byCategory.length
                      ? r.revenue.byCategory.map(
                          (c) =>
                            html`<tr>
                              <td>${c.category}</td>
                              <td class="text-end">${c.ads}</td>
                              <td class="text-end">${money(c.total)}</td>
                            </tr>`,
                        )
                      : html`<tr>
                          <td colspan="3" class="text-secondary">
                            No ad money yet.
                          </td>
                        </tr>`}
                  </tbody>
                </table>
              </div>
              <div class="col-lg-6">
                <h3 class="h6 text-uppercase text-secondary">By day</h3>
                ${r.days.length
                  ? html`<div class="small">
                      ${r.days.map(
                        (d) =>
                          html`<div
                            class="d-flex align-items-center gap-2 mb-1"
                          >
                            <span style="width:5.5rem">${day(d.day)}</span>
                            <div
                              class="flex-grow-1 bg-body-secondary rounded"
                              style="height:.7rem"
                            >
                              <div
                                class="bg-warning rounded"
                                style="height:100%;width:${Math.round(
                                  (100 * d.impressions) / max,
                                )}%"
                              ></div>
                            </div>
                            <span style="width:6rem" class="text-end"
                              >${number(d.impressions)} /
                              ${number(d.clicks)}</span
                            >
                          </div>`,
                      )}
                      <div class="text-secondary mt-1">shown / clicks</div>
                    </div>`
                  : html`<p class="text-secondary">
                      No showings in this period.
                    </p>`}
              </div>
              <div class="col-12">
                <h3 class="h6 text-uppercase text-secondary">
                  Ads in this period
                </h3>
                <div class="table-responsive">
                  <table class="table table-sm">
                    <thead>
                      <tr>
                        <th>Ad</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th class="text-end">Shown</th>
                        <th class="text-end">Clicks</th>
                        <th class="text-end">Rate</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${r.ads.length
                        ? r.ads.map(
                            (a) =>
                              html`<tr>
                                <td>
                                  <strong>${a.headline}</strong
                                  ><small class="d-block text-secondary"
                                    >${a.business} · ${a.reference}</small
                                  >
                                </td>
                                <td>${a.category}</td>
                                <td>${a.status}</td>
                                <td class="text-end">
                                  ${number(a.impressions)}
                                </td>
                                <td class="text-end">${number(a.clicks)}</td>
                                <td class="text-end">${a.ctr}%</td>
                              </tr>`,
                          )
                        : html`<tr>
                            <td colspan="6" class="text-secondary">
                              Nothing shown in this period.
                            </td>
                          </tr>`}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>`,
        );
      } catch (err) {
        mount(
          out,
          html`<div class="alert alert-danger">
            ${err.userMessage ?? err.message}
          </div>`,
        );
      }
    };
    box.querySelector("#repForm").addEventListener("submit", (e) => {
      e.preventDefault();
      run();
    });
    run();
  }
}
