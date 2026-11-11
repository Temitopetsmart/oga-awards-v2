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
        html`<p class="text-secondary">
            Sender details, the SMTP connection, the wording of every email,
            test sends and the outbox now live in one place.
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
        "Committee recruitment communications",
        html`
          <p class="text-secondary">
            Configure the intended email provider, sender details, WhatsApp
            onboarding link and the messages used throughout committee
            recruitment. Real delivery will be connected by the backend.
          </p>

          <form id="recruitmentCommsForm" novalidate>
            <h3 class="h6 mt-3">Email delivery</h3>
            <div class="row">
              ${field({
                label: "Email delivery method",
                name: "emailMode",
                options: [
                  { value: "smtp", label: "SMTP / PHPMailer" },
                  { value: "domain", label: "Hosting provider email" },
                  { value: "api", label: "Email provider API" },
                ],
                value: s.communications?.emailMode ?? "smtp",
                col: "col-md-6",
              })}
              ${field({
                label: "SMTP host",
                name: "smtpHost",
                value: s.communications?.smtpHost ?? "",
                placeholder: "mail.example.com",
                col: "col-md-6",
              })}
              ${field({
                label: "SMTP port",
                name: "smtpPort",
                type: "number",
                value: s.communications?.smtpPort ?? "587",
                col: "col-md-4",
              })}
              ${field({
                label: "SMTP username",
                name: "smtpUsername",
                value: s.communications?.smtpUsername ?? "",
                col: "col-md-4",
              })}
              ${field({
                label: "SMTP encryption",
                name: "smtpEncryption",
                options: [
                  { value: "tls", label: "TLS (usually port 587)" },
                  { value: "ssl", label: "SSL (usually port 465)" },
                  { value: "none", label: "None" },
                ],
                value: s.communications?.smtpEncryption ?? "tls",
                col: "col-md-4",
              })}
              ${field({
                label: "SMTP password",
                name: "smtpPassword",
                type: "password",
                value: "",
                autocomplete: "new-password",
                placeholder: "Enter a new SMTP password",
                help: s.communications?.smtpPasswordConfigured
                  ? "A password is already saved. Leave blank to keep the existing password."
                  : "Enter your SMTP password to enable authenticated delivery.",
                col: "col-md-6",
              })}
              ${field({
                label: "Sender name",
                name: "fromName",
                value: s.communications?.fromName ?? "OGA Awards",
                col: "col-md-4",
              })}
              ${field({
                label: "Sender email",
                name: "fromEmail",
                type: "email",
                value: s.communications?.fromEmail ?? "",
                col: "col-md-6",
              })}
              ${field({
                label: "Reply-to email",
                name: "replyTo",
                type: "email",
                value: s.communications?.replyTo ?? "",
                col: "col-md-6",
              })}
            </div>

            <div class="alert alert-info">
              SMTP credentials are stored securely on the server. The password
              is encrypted before it is saved and is never sent back to this
              page. Leave the password field blank to keep the current password.
            </div>

            <h3 class="h6 mt-4">WhatsApp and onboarding</h3>
            <div class="row">
              ${field({
                label: "Committee WhatsApp group invite URL",
                name: "whatsappGroupUrl",
                type: "url",
                value: s.communications?.whatsappGroupUrl ?? "",
                placeholder: "https://chat.whatsapp.com/...",
                col: "col-12",
                help: "Applicants receive this only in the approved/onboarding message.",
              })}
            </div>

            <h3 class="h6 mt-4">Email message templates</h3>
            <p class="text-secondary small">
              Supported placeholders: {{name}}, {{edition}}, {{screening_date}},
              {{screening_time}}, {{screening_venue}}, {{message}} and
              {{whatsapp_link}}.
            </p>

            <div class="row">
              ${field({
                label: "Application received",
                name: "templateReceived",
                type: "textarea",
                rows: 3,
                value:
                  s.communications?.templateReceived ??
                  "Hello {{name}}, your committee application for {{edition}} has been received. We will contact you about the next stage.",
                col: "col-12",
              })}
              ${field({
                label: "Shortlisted for screening",
                name: "templateShortlisted",
                type: "textarea",
                rows: 4,
                value:
                  s.communications?.templateShortlisted ??
                  "Hello {{name}}, you have been shortlisted for {{edition}} screening. Date: {{screening_date}}. Time: {{screening_time}}. Venue: {{screening_venue}}. {{message}}",
                col: "col-12",
              })}
              ${field({
                label: "Approved",
                name: "templateApproved",
                type: "textarea",
                rows: 4,
                value:
                  s.communications?.templateApproved ??
                  "Congratulations {{name}}! Your application for {{edition}} has been approved. {{message}} Join the committee group: {{whatsapp_link}}",
                col: "col-12",
              })}
              ${field({
                label: "Not selected / reserve",
                name: "templateUnsuccessful",
                type: "textarea",
                rows: 4,
                value:
                  s.communications?.templateUnsuccessful ??
                  "Hello {{name}}, thank you for participating in the {{edition}} committee recruitment process. {{message}}",
                col: "col-12",
              })}
              ${field({
                label: "Reconsideration",
                name: "templateReconsideration",
                type: "textarea",
                rows: 3,
                value:
                  s.communications?.templateReconsideration ??
                  "Hello {{name}}, a committee position may have become available for {{edition}}. We would like to know if you are still interested. {{message}}",
                col: "col-12",
              })}
            </div>

            ${save("Save recruitment communications")}
          </form>
        `,
        "cardRecruitmentComms",
      )}
      ${card(
        "Payment gateway configuration",
        html`
          <p class="text-secondary">
            Select the payment provider and configure its non-secret settings.
            Secret keys must be stored on the backend before live payments can
            be enabled.
          </p>
          <form id="gatewaySettingsForm" novalidate>
            <div class="row">
              ${field({
                label: "Payment provider",
                name: "gatewayProvider",
                options: [
                  { value: "flutterwave", label: "Flutterwave" },
                  { value: "paystack", label: "Paystack" },
                  { value: "none", label: "Not configured" },
                ],
                value: s.gateway?.provider ?? "none",
                col: "col-md-6",
              })}
              ${field({
                label: "Environment",
                name: "gatewayEnvironment",
                options: [
                  { value: "test", label: "Test mode" },
                  { value: "live", label: "Live mode" },
                ],
                value: s.gateway?.environment ?? "test",
                col: "col-md-6",
              })}
              ${field({
                label: "Public key",
                name: "gatewayPublicKey",
                value: s.gateway?.publicKey ?? "",
                col: "col-12",
              })}
            </div>
            <div class="alert alert-info">
              Secret keys and the webhook secret hash are entered in the
              “Payment secrets” card further down this page. They are encrypted
              and never shown again.
            </div>
            ${save("Save gateway preferences")}
          </form>
        `,
        "cardGatewaySettings",
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

    bindForm(root.querySelector("#gatewaySettingsForm"), {
      schema: {
        gatewayProvider: [
          rules.custom((v) =>
            ["flutterwave", "paystack", "none"].includes(v)
              ? null
              : "Choose a supported provider.",
          ),
        ],
        gatewayEnvironment: [
          rules.custom((v) =>
            ["test", "live"].includes(v) ? null : "Choose test or live mode.",
          ),
        ],
      },
      async onSubmit(v) {
        await saveSettings("gateway", {
          provider: v.gatewayProvider,
          environment: v.gatewayEnvironment,
          publicKey: v.gatewayPublicKey.trim(),
        });
        notify.toast("success", "Gateway preferences saved.");
      },
    });
  }
}
