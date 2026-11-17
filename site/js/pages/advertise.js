import { APP, ROOT } from "../../../shared/js/config/app.config.js";
import { html, mount, esc } from "../../../shared/js/core/dom.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { pageHero, pageBody } from "../sections/page-sections.js";

export const title = "Advertise";

const api = (path, init) => fetch(`${APP.data.apiBase}${path}`, init);
const json = async (res) => {
  try {
    return await res.json();
  } catch {
    return null;
  }
};
const post = async (path, body) => {
  const res = await api(path, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify(body),
  });
  const data = await json(res);
  if (!res.ok)
    throw Object.assign(
      new Error(data?.error ?? "Something went wrong. Please try again."),
      { data },
    );
  return data;
};
const date = (d) =>
  d ? formatDate(d.replace(" ", "T"), { dateStyle: "medium" }) : "";

export default async function advertise() {
  return html`${pageHero({
    eyebrow: "Advertise with us",
    title: "Put your business in front of the awards audience",
    lead: "Pick a package, upload your details and pay securely online. Every ad is reviewed before it goes live, and it runs for the period you chose.",
    crumbs: [{ label: "Advertise" }],
  })}
  ${pageBody(
    html`<div id="adPage" aria-live="polite">
      <div class="oga-skeleton" style="height:12rem"></div>
    </div>`,
  )}`;
}

export async function init(root, ctx, { params }) {
  const box = root.querySelector("#adPage");
  const ref = (params.get("ref") ?? "").toUpperCase();
  const email = params.get("email") ?? "";

  if (params.get("pay") === "return" && ref)
    return afterPayment(box, ref, params);
  if (ref && email) return statusPage(box, ref, email);
  return orderPage(box);
}

/* ------------------------------------------------------------------ paying: back from the payment page */

async function afterPayment(box, ref, params) {
  history.replaceState(
    null,
    "",
    `${location.pathname}?ref=${encodeURIComponent(ref)}&pay=return`,
  );
  if (params.get("status") === "cancelled") {
    mount(
      box,
      message(
        "fa-circle-info",
        "Payment cancelled",
        "You have not been charged. You can place the order again whenever you like.",
        [{ href: `${ROOT}advertise`, label: "Back to the order form" }],
      ),
    );
    return;
  }
  mount(
    box,
    html`<div class="oga-card text-center">
      <div class="spinner-border" role="status"></div>
      <p class="mt-3 mb-0">Checking your payment…</p>
    </div>`,
  );
  try {
    const r = await post(`/ads/orders/${encodeURIComponent(ref)}/confirm`, {});
    if (r.status === "paid") {
      mount(
        box,
        message(
          "fa-circle-check",
          "Payment received",
          `Thank you. Your ad (reference ${ref}) is now waiting for review. We have emailed you a receipt, with a private link to follow your ad.`,
          [{ href: `${ROOT}`, label: "Back to the home page" }],
        ),
      );
    } else if (r.status === "pending") {
      mount(
        box,
        message(
          "fa-hourglass-half",
          "Still confirming",
          r.note ||
            "Your payment is still being processed. Refresh this page in a minute.",
          [{ href: location.href, label: "Check again" }],
        ),
      );
    } else {
      mount(
        box,
        message(
          "fa-triangle-exclamation",
          "Payment not completed",
          r.note ||
            "The payment did not go through. You have not been charged.",
          [{ href: `${ROOT}advertise`, label: "Try again" }],
        ),
      );
    }
  } catch (err) {
    mount(
      box,
      message(
        "fa-triangle-exclamation",
        "We could not check the payment",
        err.message,
        [{ href: location.href, label: "Try again" }],
      ),
    );
  }
}

const message = (icon, heading, text, actions = []) =>
  html`<div class="oga-card text-center" style="max-width:36rem;margin:0 auto">
    <i class="fa-solid ${icon} fa-2x text-gold mb-3" aria-hidden="true"></i>
    <h2 class="h4">${heading}</h2>
    <p>${text}</p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      ${actions.map(
        (a) => html`<a class="btn btn-gold" href="${a.href}">${a.label}</a>`,
      )}
    </div>
  </div>`;

/* ------------------------------------------------------------------ the advertiser's private status page */

async function statusPage(box, ref, email) {
  mount(box, html`<div class="oga-skeleton" style="height:10rem"></div>`);
  let ad;
  try {
    ad = (await post("/ads/lookup", { ref, email })).ad;
  } catch (err) {
    mount(
      box,
      html`${message(
        "fa-magnifying-glass",
        "We could not find that ad",
        err.message,
        [{ href: `${ROOT}advertise`, label: "Back" }],
      )}`,
    );
    return;
  }
  const phaseText = {
    live: "Live now",
    scheduled: "Approved, starts soon",
    expired: "Ended",
  };
  const running = ad.status === "approved" || ad.status === "expired";
  const ctr = ad.impressions
    ? ((100 * ad.clicks) / ad.impressions).toFixed(1)
    : "0.0";
  mount(
    box,
    html`<div class="row gy-4">
      <div class="col-lg-7">
        <div class="oga-card">
          <p class="text-secondary small mb-1">Reference ${ad.reference}</p>
          <h2 class="h4">${ad.headline}</h2>
          <p class="mb-2">
            <span class="badge text-bg-warning"
              >${phaseText[ad.phase] ?? ad.statusLabel}</span
            >
            <span class="text-secondary small ms-1">${ad.category}</span>
          </p>
          ${ad.banner
            ? html`<img
                class="img-fluid rounded mb-3"
                style="max-height:11rem;width:100%;object-fit:cover"
                src="${mediaUrl(ad.banner)}"
                alt=""
              />`
            : ""}
          <p>${ad.text}</p>
          <p class="small text-secondary mb-1">Business: ${ad.business}</p>
          <p class="small text-secondary mb-3">Link: ${ad.link}</p>
          ${running && ad.startsAt
            ? html`<p class="mb-1">
                Runs from <strong>${date(ad.startsAt)}</strong> until
                <strong>${date(ad.endsAt)}</strong>.
              </p>`
            : ""}
          ${ad.status === "paid"
            ? html`<p class="mb-1">
                Paid, thank you. It is waiting for review; we will email you.
              </p>`
            : ""}
          ${ad.status === "awaiting_payment"
            ? html`<p class="mb-1">This order has not been paid for yet.</p>`
            : ""}
          ${ad.note
            ? html`<div class="alert alert-warning mt-3 mb-2">
                <strong>From the reviewer:</strong> ${ad.note}
              </div>`
            : ""}
          ${ad.refundDue
            ? html`<p class="small mb-0">
                The organisers will arrange your refund.
              </p>`
            : ""}
          ${ad.refundedAt
            ? html`<p class="small mb-0">
                Your refund was recorded on ${date(ad.refundedAt)}.
              </p>`
            : ""}
          ${ad.canRenew
            ? html`<button class="btn btn-gold mt-3" id="renewBtn">
                <i class="fa-solid fa-rotate me-2"></i>Renew for another
                ${ad.durationDays} days
              </button>`
            : ""}
        </div>
      </div>
      <div class="col-lg-5">
        <div class="oga-card">
          <h3 class="h5">How it is doing</h3>
          <div class="row text-center g-2 mb-3">
            <div class="col-4">
              <strong class="d-block fs-4">${number(ad.impressions)}</strong
              ><span class="small text-secondary">times shown</span>
            </div>
            <div class="col-4">
              <strong class="d-block fs-4">${number(ad.clicks)}</strong
              ><span class="small text-secondary">clicks</span>
            </div>
            <div class="col-4">
              <strong class="d-block fs-4">${ctr}%</strong
              ><span class="small text-secondary">click rate</span>
            </div>
          </div>
          ${ad.days.length
            ? html`<table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Day</th>
                    <th class="text-end">Shown</th>
                    <th class="text-end">Clicks</th>
                  </tr>
                </thead>
                <tbody>
                  ${ad.days
                    .slice(-14)
                    .reverse()
                    .map(
                      (d) =>
                        html`<tr>
                          <td>${date(d.day)}</td>
                          <td class="text-end">${number(d.impressions)}</td>
                          <td class="text-end">${number(d.clicks)}</td>
                        </tr>`,
                    )}
                </tbody>
              </table>`
            : html`<p class="text-secondary small mb-0">
                Numbers appear here once your ad starts to run. They are totals
                only; we never record who saw or clicked.
              </p>`}
        </div>
      </div>
    </div>`,
  );
  box.querySelector("#renewBtn")?.addEventListener("click", async (e) => {
    e.currentTarget.disabled = true;
    try {
      const r = await post("/ads/renew", { ref, email });
      if (r.paymentLink) return void (location.href = r.paymentLink);
      await notify.success(
        "Renewed",
        "Your renewal is set. We have emailed you.",
      );
      statusPage(box, ref, email);
    } catch (err) {
      e.currentTarget.disabled = false;
      notify.error("Could not renew", err.message);
    }
  });
}

/* ------------------------------------------------------------------ the order form */

async function orderPage(box) {
  let opt;
  try {
    const res = await api("/ad-options", {
      headers: { Accept: "application/json" },
    });
    opt = res.ok ? await res.json() : null;
  } catch {
    opt = null;
  }
  const lookupForm = html`<div class="oga-card mt-4">
    <h2 class="h5">Already ordered? Check on your ad</h2>
    <form id="lookupForm" class="row g-2 align-items-end" novalidate>
      <div class="col-md-5">
        <label class="form-label" for="lkRef">Reference</label
        ><input
          class="form-control"
          id="lkRef"
          name="ref"
          placeholder="AD-XXXXXXXXXX"
          autocomplete="off"
        />
      </div>
      <div class="col-md-5">
        <label class="form-label" for="lkEmail">Email used for the order</label
        ><input
          class="form-control"
          id="lkEmail"
          name="email"
          type="email"
          autocomplete="email"
        />
      </div>
      <div class="col-md-2">
        <button class="btn btn-ghost w-100" type="submit">Find</button>
      </div>
    </form>
  </div>`;
  if (!opt) {
    mount(
      box,
      message(
        "fa-triangle-exclamation",
        "We couldn’t load this page",
        "Please check your connection and try again.",
        [{ href: location.href, label: "Reload" }],
      ),
    );
    return;
  }
  if (!opt.open) {
    mount(
      box,
      html`${message(
        "fa-store-slash",
        "Ad orders are closed right now",
        "Please check back soon, or send us a message and we will let you know when packages open.",
        [{ href: `${ROOT}contact`, label: "Contact us" }],
      )}${lookupForm}`,
    );
    wireLookup(box);
    return;
  }
  const cats = opt.categories;
  mount(
    box,
    html`<div class="row gy-4 gx-lg-5">
      <div class="col-lg-8">
        <form
          id="adForm"
          class="oga-formcard"
          novalidate
          enctype="multipart/form-data"
        >
          <h2 class="h4 mb-3">1. Choose a package</h2>
          <div class="row g-3 mb-4" role="radiogroup" aria-label="Ad package">
            ${cats.map(
              (c, i) =>
                html`<div class="col-md-6">
                  <label class="oga-card d-block h-100" style="cursor:pointer">
                    <input
                      class="form-check-input me-2"
                      type="radio"
                      name="category_id"
                      value="${c.id}"
                      ${i === 0 ? "checked" : ""}
                    />
                    <strong>${c.name}</strong>
                    <span class="d-block fs-5 text-gold"
                      >${c.price > 0 ? money(c.price) : "Free"}
                      <small class="text-secondary fs-6"
                        >for ${c.durationDays} days</small
                      ></span
                    >
                    <span class="d-block small text-secondary"
                      >${c.description}</span
                    >
                    <span class="d-block small mt-1"
                      >${c.mediaMode === "text"
                        ? "Text only"
                        : c.mediaMode === "image_video"
                          ? "Logo, banner and a video link"
                          : "Logo and banner"}</span
                    >
                  </label>
                </div>`,
            )}
          </div>
          <h2 class="h4 mb-3">2. Your business</h2>
          <div class="row g-3 mb-4">
            ${inp("business_name", "Business name", {
              col: "col-md-6",
              max: 120,
              req: true,
            })}
            ${inp("contact_name", "Your name", {
              col: "col-md-6",
              max: 100,
              req: true,
              ac: "name",
            })}
            ${inp("email", "Email", {
              col: "col-md-6",
              type: "email",
              max: 190,
              req: true,
              ac: "email",
              help: "Your receipt and status link are sent here.",
            })}
            ${inp("phone", "Phone number", {
              col: "col-md-6",
              type: "tel",
              max: 30,
              req: true,
              ac: "tel",
            })}
          </div>
          <h2 class="h4 mb-3">3. Your ad</h2>
          <div class="row g-3 mb-4">
            ${inp("headline", "Headline", {
              col: "col-12",
              req: true,
              id: "fHeadline",
            })}
            <div class="col-12">
              <label class="form-label" for="fBody">Description</label
              ><textarea
                class="form-control"
                id="fBody"
                name="body"
                rows="3"
              ></textarea>
              <div class="form-text" id="bodyHelp"></div>
              <div class="invalid-feedback" data-err="body"></div>
            </div>
            ${inp("link_url", "Where should the ad link to?", {
              col: "col-md-8",
              type: "url",
              max: 500,
              req: true,
              ph: "https://",
              help: "A full https:// address.",
            })}
            ${inp("cta_label", "Button text (optional)", {
              col: "col-md-4",
              max: 30,
              ph: opt.defaultCta ?? "Learn more",
            })}
            <div class="col-md-6" data-media="logo">
              <label class="form-label" for="fLogo"
                >Logo
                <span
                  class="text-secondary small"
                  data-req="logo"
                ></span></label
              ><input
                class="form-control"
                id="fLogo"
                name="logo"
                type="file"
                accept="image/png,image/jpeg,image/webp"
              />
              <div class="form-text">JPG, PNG or WebP, at least 80 pixels.</div>
              <div class="invalid-feedback" data-err="logo"></div>
            </div>
            <div class="col-md-6" data-media="banner">
              <label class="form-label" for="fBanner"
                >Banner
                <span
                  class="text-secondary small"
                  data-req="banner"
                ></span></label
              ><input
                class="form-control"
                id="fBanner"
                name="banner"
                type="file"
                accept="image/png,image/jpeg,image/webp"
              />
              <div class="form-text">
                Wide pictures work best (about 4 times wider than tall).
              </div>
              <div class="invalid-feedback" data-err="banner"></div>
            </div>
            <div class="col-12" data-media="video">
              <label class="form-label" for="fVideo"
                >Video link (optional)</label
              ><input
                class="form-control"
                id="fVideo"
                name="video_url"
                type="url"
                placeholder="https://www.youtube.com/watch?v=…"
              />
              <div class="form-text">
                YouTube or Vimeo only. It plays only when a visitor presses
                Watch, never by itself.
              </div>
              <div class="invalid-feedback" data-err="video_url"></div>
            </div>
          </div>
          ${opt.terms
            ? html`<div class="form-check mb-3">
                <input
                  class="form-check-input"
                  type="checkbox"
                  id="fAccept"
                  name="accept"
                  value="1"
                /><label class="form-check-label small" for="fAccept"
                  >${opt.terms}</label
                >
                <div class="invalid-feedback" data-err="accept"></div>
              </div>`
            : ""}
          <div
            class="alert alert-danger d-none"
            id="adError"
            role="alert"
          ></div>
          <button class="btn btn-gold btn-lg" type="submit" id="adSubmit">
            <i class="fa-solid fa-lock me-2"></i
            ><span id="adSubmitText">Continue</span>
          </button>
        </form>
      </div>
      <div class="col-lg-4">
        <div class="oga-card">
          <h2 class="h5">How it works</h2>
          <ol class="small ps-3 mb-0">
            <li>Choose a package and fill in your details.</li>
            <li>Pay securely online (free packages skip this).</li>
            <li>We review your ad, usually within a day or two.</li>
            <li>
              It runs for the days you bought, then stops. You can renew it.
            </li>
          </ol>
          ${opt.intro
            ? html`<p class="small text-secondary mt-3 mb-0">${opt.intro}</p>`
            : ""}
        </div>
        ${lookupForm}
      </div>
    </div>`,
  );
  wireLookup(box);
  wireForm(box, opt);
}

const inp = (name, label, o = {}) =>
  html`<div class="${o.col ?? "col-12"}">
    <label class="form-label" for="${o.id ?? `f_${name}`}"
      >${label}${o.req
        ? html` <span class="text-danger" aria-hidden="true">*</span>`
        : ""}</label
    >
    <input
      class="form-control"
      id="${o.id ?? `f_${name}`}"
      name="${name}"
      type="${o.type ?? "text"}"
      ${o.max ? html`maxlength="${o.max}"` : ""}
      ${o.ac ? html`autocomplete="${o.ac}"` : ""}
      ${o.ph ? html`placeholder="${o.ph}"` : ""}
    />
    ${o.help ? html`<div class="form-text">${o.help}</div>` : ""}
    <div class="invalid-feedback" data-err="${name}"></div>
  </div>`;

function wireLookup(box) {
  box.querySelector("#lookupForm")?.addEventListener("submit", (e) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const ref = String(f.get("ref") ?? "").trim();
    const email = String(f.get("email") ?? "").trim();
    if (!ref || !email)
      return notify.info(
        "Enter both",
        "We need the reference and the email you used for the order.",
      );
    location.href = `${ROOT}advertise?ref=${encodeURIComponent(ref)}&email=${encodeURIComponent(email)}`;
  });
}

function wireForm(box, opt) {
  const form = box.querySelector("#adForm");
  const cats = Object.fromEntries(opt.categories.map((c) => [c.id, c]));
  const current = () =>
    cats[form.querySelector('input[name="category_id"]:checked')?.value] ??
    opt.categories[0];

  const apply = () => {
    const c = current();
    form
      .querySelector('[data-media="logo"]')
      .classList.toggle("d-none", c.mediaMode === "text");
    form
      .querySelector('[data-media="banner"]')
      .classList.toggle("d-none", c.mediaMode === "text");
    form
      .querySelector('[data-media="video"]')
      .classList.toggle("d-none", c.mediaMode !== "image_video");
    form.querySelector('[data-req="logo"]').textContent = c.requireLogo
      ? "(required)"
      : "(optional)";
    form.querySelector('[data-req="banner"]').textContent = c.requireBanner
      ? "(required)"
      : "(optional)";
    form.querySelector("#fHeadline").maxLength = c.maxHeadline;
    form.querySelector("#fBody").maxLength = c.maxText;
    form.querySelector("#bodyHelp").textContent =
      c.mediaMode === "text"
        ? `Required, up to ${c.maxText} characters.`
        : `Optional, up to ${c.maxText} characters.`;
    form.querySelector("#adSubmitText").textContent =
      c.price > 0
        ? `Continue to payment (${money(c.price)})`
        : "Send for review";
  };
  form.addEventListener(
    "change",
    (e) => e.target.name === "category_id" && apply(),
  );
  apply();

  const clear = () => {
    form
      .querySelectorAll(".is-invalid")
      .forEach((x) => x.classList.remove("is-invalid"));
    form.querySelectorAll("[data-err]").forEach((x) => (x.textContent = ""));
    form.querySelector("#adError").classList.add("d-none");
  };
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    clear();
    const btn = form.querySelector("#adSubmit");
    btn.disabled = true;
    const data = new FormData(form);
    const c = current();
    if (c.mediaMode === "text") {
      data.delete("logo");
      data.delete("banner");
    }
    // a file input left empty still sends an empty part; remove it so the server sees "no file"
    for (const k of ["logo", "banner"])
      if (data.get(k) instanceof File && !data.get(k).size) data.delete(k);
    try {
      const res = await api("/ads/apply", {
        method: "POST",
        headers: { Accept: "application/json" },
        body: data,
      });
      const r = await json(res);
      if (!res.ok) {
        for (const [k, msg] of Object.entries(r?.fields ?? {})) {
          const input = form.querySelector(`[name="${k}"]`);
          const slot = form.querySelector(`[data-err="${k}"]`);
          input?.classList.add("is-invalid");
          if (slot) {
            slot.textContent = msg;
            slot.style.display = "block";
          }
        }
        const el = form.querySelector("#adError");
        el.textContent = r?.error ?? "Something went wrong. Please try again.";
        el.classList.remove("d-none");
        el.scrollIntoView({
          block: "center",
          behavior: matchMedia("(prefers-reduced-motion: reduce)").matches
            ? "auto"
            : "smooth",
        });
        return;
      }
      if (r.paymentLink) {
        location.href = r.paymentLink;
        return;
      }
      mount(
        box,
        message(
          "fa-circle-check",
          "Order received",
          `Your ad (reference ${r.reference}) is now waiting for review. We have emailed you a receipt and a private link to follow it. Keep the reference.`,
          [
            {
              href: `${ROOT}advertise?ref=${encodeURIComponent(r.reference)}&email=${encodeURIComponent(r.email)}`,
              label: "See my ad",
            },
          ],
        ),
      );
    } catch (err) {
      console.error(err);
      const el = form.querySelector("#adError");
      el.textContent =
        "We could not reach the server. Please check your connection and try again.";
      el.classList.remove("d-none");
    } finally {
      btn.disabled = false;
    }
  });
}
