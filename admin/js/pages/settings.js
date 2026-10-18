import { html } from "../../../shared/js/core/dom.js";
import { APP, STATES } from "../../../shared/js/config/app.config.js";
import { rules } from "../../../shared/js/core/validate.js";
import { store } from "../../../shared/js/core/store.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { resolveState } from "../../../shared/js/services/event.service.js";
import { documentStore, logAudit } from "../services/admin-store.js";

export const title = "Settings";
export const perm = "settings";
export const crumbs = ["System", "Settings"];
const doc = documentStore("occasions", { seed: "occasions" });
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

export default async function settings() {
  const d = await doc.get();
  const o = d.occasions.find((x) => x.id === d.main);
  const s = store.get(SETTINGS, {});
  const v = { ...APP.voting, ...s.voting };
  const c = { ...APP.contact, ...s.contact };
  const social = Object.fromEntries(
    (s.social ?? APP.social).map((x) => [x.label, x.href]),
  );

  return html` <div class="row justify-content-center">
    <div class="col-xl-9">
      ${card(
        "Main event",
        html` <p id="liveState" class="mb-4"></p>
          <form id="eventForm" novalidate>
            <div class="row">
              ${field({
                label: "Event name",
                name: "name",
                required: true,
                value: o.name,
                col: "col-md-4",
              })}
              ${field({
                label: "Edition",
                name: "edition",
                required: true,
                value: o.edition,
                col: "col-md-3",
              })}
              ${field({
                label: "Tagline",
                name: "tagline",
                value: o.tagline,
                col: "col-md-5",
              })}
            </div>
            <h3 class="h6 mt-3">Public site state</h3>
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
            <button class="btn btn-gold mt-3" type="submit">
              Save event settings
            </button>
          </form>`,
        "cardEvent",
      )}
      ${card(
        "Voting rules",
        html` <form id="votingForm" novalidate>
          <div class="row">
            ${field({
              label: "Price per vote (₦)",
              name: "pricePerVote",
              type: "number",
              required: true,
              value: v.pricePerVote,
              col: "col-md-4",
            })}
            ${field({
              label: "Max votes per payment",
              name: "maxVotesPerPayment",
              type: "number",
              required: true,
              value: v.maxVotesPerPayment,
              col: "col-md-4",
            })}
            ${field({
              label: "Max paid votes per email, per category",
              name: "maxPaidVotesPerEmailPerCategory",
              type: "number",
              required: true,
              value: v.maxPaidVotesPerEmailPerCategory,
              col: "col-md-4",
              help: "Enforced by the server once the backend exists.",
            })}
          </div>
          <button class="btn btn-gold" type="submit">Save voting rules</button>
        </form>`,
        "cardVoting",
      )}
      ${card(
        "Contact & social links",
        html` <form id="contactForm" novalidate>
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
          <button class="btn btn-gold" type="submit">
            Save contact details
          </button>
        </form>`,
        "cardContact",
      )}
      ${card(
        "Demo data",
        html` <p>
            Everything in this admin is stored in
            <strong>this browser only</strong> until the backend exists.
            Resetting clears all demo data — votes, tickets, nominees, messages
            and admin changes — and signs you out.
          </p>
          <button
            class="btn btn-ghost"
            id="resetBtn"
            style="color:#ff8a82;border-color:rgba(229,88,79,.5)"
          >
            <i class="fa-solid fa-rotate-left me-1"></i>Reset all demo data
          </button>`,
        "cardDemo",
      )}
    </div>
  </div>`;
}

export async function init(root, { admin }) {
  const showState = (occ) => {
    const { state, source } = resolveState(occ, { allowPreview: false });
    root.querySelector("#liveState").innerHTML =
      `The public site is currently showing <strong class="text-gold">${STATES[state].label}</strong> <span class="text-secondary">(${source === "manual" ? "set manually" : "from the dates"})</span>.`;
  };
  const main = async () => {
    const d = await doc.get();
    return { d, o: d.occasions.find((x) => x.id === d.main) };
  };
  showState((await main()).o);

  const eventForm = root.querySelector("#eventForm");
  bindForm(eventForm, {
    schema: {
      name: [rules.required(), rules.max(40)],
      edition: [rules.required(), rules.max(10)],
      tagline: [rules.max(120)],
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
      const { d, o } = await main();
      Object.assign(o, {
        name: v.name.trim(),
        edition: v.edition.trim(),
        tagline: v.tagline.trim(),
        mode: v.mode,
        forceState: v.mode === "manual" ? v.forceState : null,
      });
      o.timeline = Object.fromEntries(
        TIMELINE.map(([k]) => [k, fromInput(v[k])]).filter(([, val]) => val),
      );
      o.features = {
        ...o.features,
        tickets: eventForm.elements.tickets.checked,
        committeeRecruitment: eventForm.elements.committeeRecruitment.checked,
        showVoteCounts: eventForm.elements.showVoteCounts.checked,
      };
      doc.save(d);
      showState(o);
      logAudit(admin, "Updated event settings", `${o.name} ${o.edition}`);
      notify.toast("success", "Event settings saved");
    },
  });

  const saveSettings = (patch) =>
    store.set(SETTINGS, { ...store.get(SETTINGS, {}), ...patch });
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
      saveSettings({
        voting: {
          pricePerVote: Number(v.pricePerVote),
          maxVotesPerPayment: Number(v.maxVotesPerPayment),
          maxPaidVotesPerEmailPerCategory: Number(
            v.maxPaidVotesPerEmailPerCategory,
          ),
        },
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
      saveSettings({
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

  root.querySelector("#resetBtn").addEventListener("click", async () => {
    if (
      !(await notify.confirm({
        title: "Reset all demo data?",
        text: "This deletes every vote, ticket, nominee, message and admin change stored in this browser, and signs you out.",
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
