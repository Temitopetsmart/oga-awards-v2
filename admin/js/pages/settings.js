import { html } from "../../../shared/js/core/dom.js";
import { APP, STATES } from "../../../shared/js/config/app.config.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store } from "../../../shared/js/core/store.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { resolveState } from "../../../shared/js/services/event.service.js";
import { logAudit } from "../services/admin-store.js";
import {
  getEdition,
  updateEdition,
  editionLabel,
} from "../services/edition.repo.js";

export const title = "Settings";
export const perm = "settings.view";
export const crumbs = ["Administration", "Settings"];
const SETTINGS = "admin:settings";

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
  const s = store.get(SETTINGS, {});
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
        `${editionLabel(o)} — details`,
        html` <form id="detailsForm" novalidate>
          <fieldset ${lock}>
            <div class="row">
              ${field({
                label: "Name",
                name: "name",
                required: true,
                value: o.name,
                col: "col-md-3",
              })}
              ${field({
                label: "Edition number",
                name: "edition",
                required: true,
                value: o.edition,
                col: "col-md-3",
              })}
              ${field({
                label: "Year",
                name: "year",
                type: "number",
                required: true,
                value: o.year,
                col: "col-md-2",
              })}
              ${field({
                label: "Tagline",
                name: "tagline",
                value: o.tagline,
                col: "col-md-4",
              })}
            </div>
            ${save("Save details")}
          </fieldset>
        </form>`,
        "cardDetails",
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
              )}${sw(
                "showVoteCounts",
                "Show vote counts publicly",
                o.features?.showVoteCounts,
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
                label: "Max paid votes per email, per category",
                name: "maxPaidVotesPerEmailPerCategory",
                type: "number",
                required: true,
                value: o.voting?.maxPaidVotesPerEmailPerCategory,
                col: "col-md-4",
                help: "Enforced by the server once the backend exists.",
              })}
            </div>
            ${save("Save voting rules")}
          </fieldset>
        </form>`,
        "cardVoting",
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
      ${access.isSuper
        ? card(
            "Demo data",
            html` <p>
                Everything in this admin is stored in
                <strong>this browser only</strong> until the backend exists.
                Resetting clears all demo data — editions, votes, tickets,
                nominees, messages and team — and signs you out.
              </p>
              <button
                class="btn btn-ghost"
                id="resetBtn"
                style="color:#ff8a82;border-color:rgba(229,88,79,.5)"
              >
                <i class="fa-solid fa-rotate-left me-1"></i>Reset all demo data
              </button>`,
            "cardDemo",
          )
        : ""}
    </div>
  </div>`;
}

export async function init(root, { admin, edition: ed, can }) {
  const editable = can("settings.edit");
  const showState = () => {
    const o = getEdition(ed.id);
    const el = root.querySelector("#liveState");
    if (o.status !== "active") {
      el.innerHTML = `<strong>${editionLabel(o)}</strong> is <em>${o.status}</em>, so the public site isn’t following it. These dates take effect once it’s the active edition.`;
      return;
    }
    const { state, source } = resolveState(o, { allowPreview: false });
    el.innerHTML = `The public site is currently showing <strong class="text-gold">${STATES[state].label}</strong> <span class="text-secondary">(${source === "manual" ? "set manually" : "from the dates"})</span>.`;
  };
  showState();
  if (editable) {
    const eventForm = root.querySelector("#eventForm");
    bindForm(root.querySelector("#detailsForm"), {
      schema: {
        name: [rules.required(), rules.max(30)],
        edition: [rules.required(), rules.max(10)],
        year: [
          rules.required(),
          rules.custom((v) =>
            Number.isInteger(Number(v)) &&
            Number(v) >= 2000 &&
            Number(v) <= 2100
              ? null
              : "Enter a year like 2027.",
          ),
        ],
        tagline: [rules.max(120)],
      },
      async onSubmit(v) {
        updateEdition(ed.id, (o) =>
          Object.assign(o, {
            name: v.name.trim(),
            edition: v.edition.trim(),
            year: Number(v.year),
            tagline: v.tagline.trim(),
          }),
        );
        logAudit(admin, "Updated edition details", `${v.name} ${v.edition}`);
        notify.toast("success", "Details saved");
      },
    });
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
        updateEdition(ed.id, (o) => {
          Object.assign(o, {
            mode: v.mode,
            forceState: v.mode === "manual" ? v.forceState : null,
          });
          o.timeline = Object.fromEntries(
            TIMELINE.map(([k]) => [k, fromInput(v[k])]).filter(
              ([, val]) => val,
            ),
          );
          o.features = {
            ...o.features,
            tickets: eventForm.elements.tickets.checked,
            committeeRecruitment:
              eventForm.elements.committeeRecruitment.checked,
            showVoteCounts: eventForm.elements.showVoteCounts.checked,
          };
        });
        showState();
        logAudit(
          admin,
          "Updated state, dates and features",
          editionLabel(getEdition(ed.id)),
        );
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
      },
      async onSubmit(v) {
        updateEdition(ed.id, (o) => {
          o.voting = {
            pricePerVote: Number(v.pricePerVote),
            maxVotesPerPayment: Number(v.maxVotesPerPayment),
            maxPaidVotesPerEmailPerCategory: Number(
              v.maxPaidVotesPerEmailPerCategory,
            ),
          };
        });
        logAudit(admin, "Updated voting rules", `₦${v.pricePerVote} per vote`);
        notify.toast("success", "Voting rules saved");
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
        store.set(SETTINGS, {
          ...store.get(SETTINGS, {}),
          contact: { email: v.email.trim(), phone: v.phone.trim() },
          social: APP.social.map((x) => ({
            ...x,
            href: (v[`social_${x.label}`] ?? "").trim(),
          })),
        });
        logAudit(admin, "Updated contact details");
        notify.toast("success", "Contact details saved");
      },
    });
  }
  root.querySelector("#resetBtn")?.addEventListener("click", async () => {
    if (
      !(await notify.confirm({
        title: "Reset all demo data?",
        text: "This deletes every edition, vote, ticket, nominee, message and team change stored in this browser, and signs you out.",
        confirmText: "Reset everything",
        danger: true,
      }))
    )
      return;
    Object.keys(localStorage)
      .filter((k) => k.startsWith("oga:"))
      .forEach((k) => localStorage.removeItem(k));
    location.assign("login.html");
  });
}
