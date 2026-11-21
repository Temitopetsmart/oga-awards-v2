import { html } from "../../../shared/js/core/dom.js";
import {
  getNomineeAnnouncements,
  getAccessPass,
  getMyVotes,
} from "../../../shared/js/services/auth.service.js";
import { STEPS } from "../data.js";
import { href, statusChip, card } from "../ui.js";

export const title = "Home";

const TILES = [
  [
    "fa-list-check",
    "My entries",
    "Your categories, review status and new applications.",
    "entries",
  ],
  [
    "fa-user",
    "My profile",
    "Photo, bio and details voters will see.",
    "profile",
  ],
  [
    "fa-bullhorn",
    "Share & promote",
    "Your link, promo flier and QR code.",
    "promote",
  ],
  ["fa-ticket", "Access Pass", "Your free entry to the event.", "pass"],
  ["fa-bell", "Updates", "News from the organisers.", "updates"],
  ["fa-gear", "Account", "Email, password and sign-in.", "account"],
];

const MESSAGE = {
  voting:
    "Voting is open. Rankings and other nominees’ numbers are never shown to anyone, so everyone stays in suspense until the winners are announced.",
  "voting-closed":
    "Voting has closed and the results are being verified. Winners are announced by the organisers.",
  results: "The winners have been announced.",
};

const when = (iso) =>
  new Date(iso).toLocaleString(undefined, {
    day: "numeric",
    month: "short",
    hour: "numeric",
    minute: "2-digit",
  });

/** The nominee's own votes: a total per category and the latest voters. Only drawn when the admin has switched the feed on. */
const votesCard = (v) =>
  card(
    "My votes",
    html`${v.entries.map(
        (e) =>
          html`<div class="mb-3">
            <div
              class="d-flex align-items-baseline justify-content-between gap-2"
            >
              <span class="fw-semibold">${e.category}</span>
              <span class="text-nowrap"
                ><span class="h3 mb-0 text-gold"
                  >${e.votes.toLocaleString()}</span
                >
                <small class="text-secondary"
                  >vote${e.votes === 1 ? "" : "s"}</small
                ></span
              >
            </div>
            ${e.recent.length
              ? html`<ul class="list-unstyled small mb-0 mt-2">
                  ${e.recent.map(
                    (r) =>
                      html`<li
                        class="d-flex justify-content-between gap-2 py-1 border-top"
                      >
                        <span>${r.name}</span>
                        <span class="text-nowrap text-secondary"
                          ><strong class="text-body">${r.votes}</strong>
                          vote${r.votes === 1 ? "" : "s"} · ${when(r.at)}</span
                        >
                      </li>`,
                  )}
                </ul>`
              : html`<p class="small text-secondary mb-0 mt-1">
                  No votes yet. Share your link to get them coming.
                </p>`}
          </div>`,
      )}
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-refresh-votes
      >
        <i class="fa-solid fa-rotate me-1"></i>Refresh
      </button>`,
    { icon: "fa-heart" },
  );

export function init(root) {
  root
    .querySelector("[data-refresh-votes]")
    ?.addEventListener("click", () => location.reload());
}

export default async function home(p) {
  const {
    me,
    ctx,
    cat,
    status,
    percent,
    checklist,
    isWinner,
    readOnly,
    canApply,
    mineHere,
    at,
  } = p;
  const [updates, pass, votes] = readOnly
    ? [[], null, null]
    : await Promise.all([
        getNomineeAnnouncements().catch(() => []),
        getAccessPass().catch(() => null),
        getMyVotes().catch(() => null),
      ]);

  const todo = [];
  if (!readOnly) {
    if (p.attention.entries)
      todo.push([
        "fa-triangle-exclamation",
        "An entry needs your changes",
        "Read the reviewer’s note and send it for review again.",
        "entries",
      ]);
    if (!me.emailVerified)
      todo.push([
        "fa-envelope-circle-check",
        "Verify your email",
        "It is how we send decisions and your Access Pass.",
        "account",
      ]);
    if (percent < 100)
      todo.push([
        "fa-user-pen",
        `Finish your profile (${percent}%)`,
        `Still to add: ${checklist
          .filter(([, ok]) => !ok)
          .map(([l]) => l.replace(/^An? /, "").toLowerCase())
          .join(", ")}`,
        "profile",
      ]);
    if (canApply)
      todo.push([
        "fa-plus",
        mineHere.length
          ? "Enter another category"
          : `Apply for ${ctx.occasion.edition}`,
        "Same profile and photo for every category.",
        "entries",
      ]);
    if (pass?.enabled && pass.eligible && !pass.claimed)
      todo.push([
        "fa-ticket",
        "Claim your free Access Pass",
        "One ticket per seat, each with its own QR code.",
        "pass",
      ]);
    if (
      me.status === "approved" &&
      (ctx.state === "nominations" || ctx.state === "voting")
    )
      todo.push([
        "fa-bullhorn",
        "Share your profile",
        "Send your link or promo flier to your supporters.",
        "promote",
      ]);
  }

  return html` <div class="pt-hero mb-4">
      <span class="pt-eyebrow"
        >${ctx.occasion.name} ${ctx.occasion.edition}</span
      >
      <h2>Hello, ${me.name.split(" ")[0]}</h2>
      <p class="mb-2">
        ${cat
          ? html`You’re entered in <strong>${cat.name}</strong>. `
          : ""}${statusChip(me.status)}
      </p>
      <p class="mb-0 text-secondary">${MESSAGE[ctx.state] ?? status.text}</p>
      <ol class="pt-steps" aria-label="Edition progress">
        ${STEPS.map(
          ([, label], i) =>
            html`<li
              class="${i === at ? "on" : ""}"
              ${i === at ? 'aria-current="step"' : ""}
            >
              ${i + 1}. ${label}
            </li>`,
        )}
      </ol>
    </div>

    ${isWinner
      ? html`<div class="pt-card text-center mb-4">
          <h2 class="h4">
            <i class="fa-solid fa-trophy text-gold me-2"></i>Congratulations,
            you won${cat ? ` ${cat.name}` : ""}!
          </h2>
          <p class="mb-0">The organisers have announced the winners.</p>
        </div>`
      : ""}
    ${votes?.enabled && votes.entries.length
      ? html`<div class="mb-4">${votesCard(votes)}</div>`
      : ""}
    ${todo.length
      ? html`<h2 class="h6 text-uppercase text-secondary mb-2">To do</h2>
          <div class="d-grid gap-2 mb-4">
            ${todo.map(
              ([icon, t, sub, to]) =>
                html`<a class="pt-todo" href="${href(p, to)}"
                  ><i class="fa-solid ${icon}"></i
                  ><span><strong>${t}</strong><small>${sub}</small></span
                  ><i
                    class="fa-solid fa-chevron-right ms-auto"
                    style="width:auto;font-size:.8rem"
                  ></i
                ></a>`,
            )}
          </div>`
      : readOnly
        ? ""
        : html`<div class="pt-card mb-4">
            <i class="fa-solid fa-circle-check text-success me-2"></i>You’re all
            caught up. Nothing needs your attention right now.
          </div>`}

    <div class="row g-3 mb-4">
      ${TILES.map(
        ([icon, t, sub, to]) =>
          html`<div class="col-sm-6 col-xl-4">
            <a class="pt-tile" href="${href(p, to)}"
              ><i class="fa-solid ${icon}"></i
              ><span><strong>${t}</strong><small>${sub}</small></span></a
            >
          </div>`,
      )}
    </div>

    ${updates.length
      ? card(
          "Latest updates",
          html`${updates.slice(0, 3).map(
              (a) =>
                html`<div class="mb-3">
                  <strong>${a.pinned ? "📌 " : ""}${a.title}</strong
                  ><time class="small text-secondary ms-2"
                    >${a.at ? new Date(a.at).toLocaleDateString() : ""}</time
                  >
                  <p class="mb-0 small" style="white-space:pre-line">
                    ${a.body}
                  </p>
                </div>`,
            )}<a href="${href(p, "updates")}" class="small">All updates</a>`,
          { icon: "fa-bullhorn" },
        )
      : ""}`;
}
