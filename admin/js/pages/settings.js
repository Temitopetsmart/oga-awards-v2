import { html, esc } from "../../../shared/js/core/dom.js";
import { APP, STATES, ROOT } from "../../../shared/js/config/app.config.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store } from "../../../shared/js/core/store.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { resolveState } from "../../../shared/js/services/event.service.js";
import { logAudit } from "../services/admin-store.js";
import { getEdition, editionLabel } from "../services/edition.repo.js";
import { fetchSettings, saveSettings } from "../services/settings.api.js";
import { hubCards, initHub } from "./settings-hub.js";
import {
  // updateEditionDetails,
  saveEditionState,
  saveVotingRules,
  saveAccessPass,
  saveFlier,
} from "../services/edition.api.js";

export const title = "Settings";
export const perm = "settings.view";
export const crumbs = ["Administration", "Settings"];
const SETTINGS = "admin:settings";
let hubData = {}; // the settings loaded for this visit, shared with the hub cards

const TIMELINE = [
  ["nominationsOpen", "Nominations open"],
  ["nominationsClose", "Nominations close"],
  ["votingOpen", "Voting opens"],
  ["votingClose", "Voting closes"],
  ["resultsPublish", "Winners announced"],
  ["ceremony", "Awards night"],
];
/** UTC ISO <-> datetime-local in Lagos time (UTC+1, no daylight saving). */
const toInput = (iso) =>
  iso
    ? new Date(new Date(iso).getTime() + 36e5).toISOString().slice(0, 16)
    : "";
const fromInput = (v) => (v ? `${v}:00+01:00` : undefined);

const sw = (name, label, on) =>
  html`<div class="form-check form-switch mb-2">
    <input
      class="form-check-input"
      type="checkbox"
      role="switch"
      id="f-${name}"
      name="${name}"
      ${on ? "checked" : ""}
    />
    <label class="form-check-label" for="f-${name}">${label}</label>
  </div>`;
const card = (heading, body, id) =>
  html`<div class="card mb-4" id="${id}">
    <div class="card-header"><h2 class="card-title mb-0">${heading}</h2></div>
    <div class="card-body">${body}</div>
  </div>`;

export default async function settings({ edition: ed, can, access }) {
  const o = getEdition(ed.id);
  const editable = can("settings.edit");
  const s = await fetchSettings(ed.id);
  hubData = s;
  const c = { ...APP.contact, ...s.contact };
  const social = Object.fromEntries(
    (s.social ?? APP.social).map((x) => [x.label, x.href]),
  );
  const comms = s.communications ?? {};
  const emailReady = Boolean(
    comms.smtpHost && comms.smtpPasswordConfigured && comms.fromEmail,
  );
  const lock = editable ? "" : "disabled";
  const save = (label) =>
    editable
      ? html`<button class="btn btn-gold mt-3" type="submit">${label}</button>`
      : "";

  return html` <div class="row justify-content-center">
    <div class="col-xl-9">
      ${editable
        ? ""
        : html`<div class="oga-banner oga-banner--warn" role="note">
            <span
              ><i class="fa-solid fa-lock me-2"></i>These settings are read-only
              for
              you${access.readOnly
                ? " because this edition is closed"
                : ""}.</span
            >
          </div>`}
      ${card(
        "Email",
        html`<p class="mb-2">
            ${emailReady
              ? html`<span class="badge text-bg-success">Connected</span>`
              : html`<span class="badge text-bg-warning">Not set up</span>`}
            <span class="ms-2 text-secondary small"
              >${emailReady
                ? `Sending as ${comms.fromName || "the site"} <${comms.fromEmail}>`
                : "No email is sent until the SMTP connection and sender are saved."}</span
            >
          </p>
          <p class="text-secondary small">
            The connection, sender details, the wording of every email, test
            sends and the outbox are all managed on the Email page.
          </p>
          <a class="btn btn-gold" href="${ROOT}admin/email"
            >Open Email settings</a
          >`,
        "cardEmailPointer",
      )}
      ${card(
        "Public site state & key dates",
        html` <p id="liveState" class="mb-4"></p>
          <form id="eventForm" novalidate>
            <fieldset ${lock}>
              <div class="row">
                ${field({
                  label: "How is the state chosen?",
                  name: "mode",
                  required: true,
                  value: o.mode ?? "auto",
                  col: "col-md-6",
                  options: [
                    {
                      value: "auto",
                      label: "Automatically from the dates below",
                    },
                    { value: "manual", label: "Manually — I pick the state" },
                  ],
                })}
                ${field({
                  label: "State (when manual)",
                  name: "forceState",
                  value: o.forceState ?? "",
                  col: "col-md-6",
                  placeholder: "—",
                  options: Object.entries(STATES).map(([k, st]) => ({
                    value: k,
                    label: st.label,
                  })),
                })}
              </div>
              <h3 class="h6 mt-3">
                Key dates <small class="text-secondary">(Lagos time)</small>
              </h3>
              <div class="row">
                ${TIMELINE.map(([k, label]) =>
                  field({
                    label,
                    name: k,
                    type: "datetime-local",
                    value: toInput(o.timeline?.[k]),
                    col: "col-md-6",
                  }),
                )}
              </div>
              <h3 class="h6 mt-3">Features</h3>
              ${sw("tickets", "Ticket sales", o.features?.tickets)}${sw(
                "committeeRecruitment",
                "Committee recruitment",
                o.features?.committeeRecruitment,
              )}
              ${save("Save state, dates & features")}
            </fieldset>
          </form>`,
        "cardEvent",
      )}
      ${card(
        "Voting rules",
        html` <form id="votingForm" novalidate>
          <fieldset ${lock}>
            <div class="row">
              ${field({
                label: "Price per vote (₦)",
                name: "pricePerVote",
                type: "number",
                required: true,
                value: o.voting?.pricePerVote,
                col: "col-md-4",
              })}
              ${field({
                label: "Max votes per payment",
                name: "maxVotesPerPayment",
                type: "number",
                required: true,
                value: o.voting?.maxVotesPerPayment,
                col: "col-md-4",
              })}
              ${field({
                label: "Max paid payments per email, per category",
                name: "maxPaidVotesPerEmailPerCategory",
                type: "number",
                required: true,
                value: o.voting?.maxPaidVotesPerEmailPerCategory,
                col: "col-md-4",
                help: "How many separate paid vote payments one email can make for one category. Enforced by the server.",
              })}
              ${field({
                label: "Nominee registration fee (₦)",
                name: "nomineeRegistrationFee",
                type: "number",
                required: true,
                value: o.voting?.nomineeRegistrationFee ?? 0,
                col: "col-md-4",
                help: "Charged once per nominee for this edition, however many categories they enter. Enter 0 to make registration free.",
              })}
              ${field({
                label: "Categories per nominee",
                name: "maxCategoriesPerNominee",
                type: "number",
                required: true,
                value: o.voting?.maxCategoriesPerNominee ?? 3,
                col: "col-md-4",
                help: "How many categories one nominee may enter in this edition. Enter 1 to allow only one. Enforced by the server.",
              })}
            </div>
            ${save("Save voting rules")}
          </fieldset>
        </form>`,
        "cardVoting",
      )}
      ${card(
        "Nominee Access Pass",
        html` <form id="passForm" novalidate>
          <fieldset ${lock}>
            <p class="small text-secondary">
              A free event ticket for approved nominees. Each nominee claims one
              pass for this edition and can bring the number of guests you set.
              Nominees come whether they win or not; winners stay secret until
              you publish them. Passes use the normal gate scanner.
            </p>
            ${sw(
              "passEnabled",
              "Nominees can claim an Access Pass",
              o.accessPass?.enabled,
            )}
            <div class="row">
              ${field({
                label: "Guests per pass",
                name: "guests",
                type: "number",
                required: true,
                value: o.accessPass?.guests ?? 0,
                col: "col-md-4",
                help: "0 to 5 extra tickets with each pass. A later change applies to passes claimed after it.",
              })}
            </div>
            ${save("Save Access Pass")}
          </fieldset>
        </form>`,
        "cardPass",
      )}
      ${card(
        "Nominee promo flier",
        html` <form id="flierForm" novalidate>
          <fieldset ${lock}>
            <p class="small text-secondary">
              Nominees download a flier with their photo, name and a QR code to
              their profile. You decide how it looks and what it says.
            </p>
            ${sw(
              "flierEnabled",
              "Nominees can download a flier",
              o.flier?.enabled ?? true,
            )}
            ${sw(
              "showQr",
              "Show a QR code to the nominee’s profile",
              o.flier?.showQr ?? true,
            )}
            ${sw(
              "showCategory",
              "Show the category",
              o.flier?.showCategory ?? true,
            )}
            <div class="row">
              ${field({
                label: "Headline",
                name: "headline",
                required: true,
                value: o.flier?.headline ?? "Vote for me",
                col: "col-md-4",
                help: "Up to 40 characters.",
              })}
              ${field({
                label: "Tagline",
                name: "tagline",
                value: o.flier?.tagline ?? "",
                col: "col-md-4",
                help: "Optional, up to 80 characters.",
              })}
              ${field({
                label: "Footer",
                name: "footer",
                value: o.flier?.footer ?? "",
                col: "col-md-4",
                help: "Shown above the QR code, up to 80 characters.",
              })}
              ${field({
                label: "Background colour",
                name: "bg",
                type: "color",
                value: o.flier?.bg ?? "#14110a",
                col: "col-md-4",
              })}
              ${field({
                label: "Accent colour",
                name: "accent",
                type: "color",
                value: o.flier?.accent ?? "#d4a84b",
                col: "col-md-4",
              })}
              ${field({
                label: "Text colour",
                name: "text",
                type: "color",
                value: o.flier?.text ?? "#ffffff",
                col: "col-md-4",
              })}
            </div>
            ${save("Save flier")}
          </fieldset>
        </form>`,
        "cardFlier",
      )}
      ${card(
        "Contact & social links (all editions)",
        html` <form id="contactForm" novalidate>
          <fieldset ${lock}>
            <div class="row">
              ${field({
                label: "Contact email",
                name: "email",
                type: "email",
                value: c.email,
                col: "col-md-6",
              })}
              ${field({
                label: "Contact phone",
                name: "phone",
                type: "tel",
                value: c.phone,
                col: "col-md-6",
              })}
              ${APP.social.map((x) =>
                field({
                  label: x.label,
                  name: `social_${x.label}`,
                  type: "url",
                  value: social[x.label] ?? "",
                  col: "col-md-6",
                  placeholder: "https://…",
                  help: "Leave empty to hide the icon.",
                }),
              )}
            </div>
            ${save("Save contact details")}
          </fieldset>
        </form>`,
        "cardContact",
      )}
      ${card(
        "Site mode",
        html`<p class="text-secondary">
            Switch maintenance on to close the public site while you work.
            Visitors see a “We’ll be right back” page and nobody can vote, buy
            tickets, register or send messages. The admin area keeps working and
            payments already in progress still complete.
          </p>
          <form id="maintenanceForm" novalidate>
            <fieldset ${lock}>
              ${sw(
                "maintenanceOn",
                "Maintenance mode is on",
                Boolean(s.maintenance?.enabled),
              )}
              ${field({
                label: "Message shown to visitors (optional)",
                name: "maintenanceMessage",
                value: s.maintenance?.message ?? "",
                col: "col-12",
                placeholder: "We’ll be back at 6 PM.",
              })}
            </fieldset>
            ${save("Save site mode")}
          </form>`,
        "cardMaintenance",
      )}
      ${hubCards(s, { editable, isSuper: Boolean(access?.isSuper) })}
    </div>
  </div>`;
}

export async function init(root, { admin, edition: ed, can, access }) {
  const editable = can("settings.edit");
  initHub(root, { editable, isSuper: Boolean(access?.isSuper) }, hubData);
  const showState = () => {
    const o = getEdition(ed.id);
    const el = root.querySelector("#liveState");
    if (o.status !== "active") {
      el.innerHTML = `<strong>${esc(editionLabel(o))}</strong> is <em>${o.status}</em>, so the public site isn’t following it. These dates take effect once it’s the active edition.`;
      return;
    }
    const { state, source } = resolveState(o, { allowPreview: false });
    el.innerHTML = `The public site is currently showing <strong class="text-gold">${STATES[state].label}</strong> <span class="text-secondary">(${source === "manual" ? "set manually" : "from the dates"})</span>.`;
  };
  showState();
  if (editable) {
    const eventForm = root.querySelector("#eventForm");
    // bindForm(root.querySelector("#detailsForm"), {
    //   schema: {
    //     name: [rules.required(), rules.max(30)],
    //     edition: [rules.required(), rules.max(10)],
    //     year: [
    //       rules.required(),
    //       rules.custom((v) =>
    //         Number.isInteger(Number(v)) &&
    //         Number(v) >= 2000 &&
    //         Number(v) <= 2100
    //           ? null
    //           : "Enter a year like 2027.",
    //       ),
    //     ],
    //     tagline: [rules.max(120)],
    //   },
    //   async onSubmit(v) {
    //     await updateEditionDetails(ed.id, {
    //       name: v.name.trim(),
    //       edition: v.edition.trim(),
    //       year: Number(v.year),
    //       tagline: v.tagline.trim(),
    //     });
    //     notify.toast("success", "Details saved");
    //   },
    // });
    bindForm(eventForm, {
      schema: {
        forceState: [
          rules.custom((v, all) =>
            all.mode === "manual" && !v ? "Pick the state to show." : null,
          ),
        ],
        ...Object.fromEntries(
          TIMELINE.map(([k], i) => [
            k,
            [
              rules.custom((v, all) => {
                if (!v) return null;
                const prev = TIMELINE.slice(0, i)
                  .reverse()
                  .find(([pk]) => all[pk]);
                return prev && new Date(v) < new Date(all[prev[0]])
                  ? `Can’t be before “${prev[1]}”.`
                  : null;
              }),
            ],
          ]),
        ),
      },
      async onSubmit(v) {
        await saveEditionState(ed.id, {
          mode: v.mode,
          forceState: v.mode === "manual" ? v.forceState : null,
          timeline: Object.fromEntries(
            TIMELINE.map(([k]) => [k, fromInput(v[k])]).filter(
              ([, val]) => val,
            ),
          ),
          features: {
            tickets: eventForm.elements.tickets.checked,
            committeeRecruitment:
              eventForm.elements.committeeRecruitment.checked,
          },
        });
        showState();
        notify.toast("success", "Saved");
      },
    });
    const whole = (min, max) =>
      rules.custom((v) =>
        Number.isInteger(Number(v)) && Number(v) >= min && Number(v) <= max
          ? null
          : `Enter a whole number from ${min} to ${max}.`,
      );
    bindForm(root.querySelector("#votingForm"), {
      schema: {
        pricePerVote: [rules.required(), whole(1, 1000000)],
        maxVotesPerPayment: [rules.required(), whole(1, 10000)],
        maxPaidVotesPerEmailPerCategory: [rules.required(), whole(1, 1000)],
        nomineeRegistrationFee: [rules.required(), whole(0, 10000000)],
        maxCategoriesPerNominee: [rules.required(), whole(1, 20)],
      },
      async onSubmit(v) {
        await saveVotingRules(ed.id, {
          pricePerVote: Number(v.pricePerVote),
          maxVotesPerPayment: Number(v.maxVotesPerPayment),
          maxPaidVotesPerEmailPerCategory: Number(
            v.maxPaidVotesPerEmailPerCategory,
          ),
          nomineeRegistrationFee: Number(v.nomineeRegistrationFee),
          maxCategoriesPerNominee: Number(v.maxCategoriesPerNominee),
        });
        notify.toast("success", "Voting rules saved");
      },
    });
    bindForm(root.querySelector("#passForm"), {
      schema: { guests: [rules.required(), whole(0, 5)] },
      async onSubmit(v, form) {
        await saveAccessPass(ed.id, {
          enabled: form.elements.passEnabled.checked,
          guests: Number(v.guests),
        });
        notify.toast("success", "Access Pass saved");
      },
    });
    bindForm(root.querySelector("#flierForm"), {
      schema: {
        headline: [rules.required(), rules.max(40)],
        tagline: [rules.max(80)],
        footer: [rules.max(80)],
      },
      async onSubmit(v, form) {
        await saveFlier(ed.id, {
          enabled: form.elements.flierEnabled.checked,
          showQr: form.elements.showQr.checked,
          showCategory: form.elements.showCategory.checked,
          headline: v.headline.trim(),
          tagline: (v.tagline ?? "").trim(),
          footer: (v.footer ?? "").trim(),
          bg: v.bg,
          accent: v.accent,
          text: v.text,
        });
        notify.toast("success", "Flier saved");
      },
    });
    bindForm(root.querySelector("#contactForm"), {
      schema: {
        email: [rules.email()],
        phone: [rules.phone()],
        ...Object.fromEntries(
          APP.social.map((x) => [
            `social_${x.label}`,
            [
              rules.custom((v) =>
                !v || /^https:\/\/\S+$/.test(v.trim())
                  ? null
                  : "Use a full https:// address.",
              ),
            ],
          ]),
        ),
      },
      async onSubmit(v) {
        await saveSettings("contact", {
          email: v.email.trim(),
          phone: v.phone.trim(),
        });
        await saveSettings(
          "social",
          APP.social.map((x) => ({
            ...x,
            href: (v[`social_${x.label}`] ?? "").trim(),
          })),
        );
        notify.toast("success", "Contact details saved");
      },
    });

    bindForm(root.querySelector("#maintenanceForm"), {
      schema: {},
      async onSubmit(v, form) {
        const on = form.elements.maintenanceOn.checked;
        if (
          on &&
          !(await notify.confirm({
            title: "Close the public site?",
            text: "Everyone will see the maintenance page until you switch this off.",
            confirmText: "Close the site",
            danger: true,
          }))
        )
          return;
        await saveSettings("maintenance", {
          enabled: on,
          message: (v.maintenanceMessage ?? "").trim(),
        });
        notify.toast(
          "success",
          on ? "Maintenance mode is ON" : "The site is open",
        );
      },
    });
  }
}
