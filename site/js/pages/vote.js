import { APP, ROOT } from "../../../shared/js/config/app.config.js";
import { html, mount, safeClass } from "../../../shared/js/core/dom.js";
import { money } from "../../../shared/js/core/format.js";
import { rules } from "../../../shared/js/core/validate.js";
import { field } from "../../../shared/js/components/form-fields.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  getCategories,
  getNominees,
} from "../../../shared/js/services/catalog.service.js";
import {
  createOrder,
  confirmOrder,
  validateVotes,
  orderTotal,
} from "../../../shared/js/services/vote.service.js";
import { checkout } from "../../../shared/js/services/payment.service.js";
import { pageHero, pageBody, stateGate } from "../sections/page-sections.js";

export const title = "Vote";

const S = {
  step: 1,
  categoryId: "",
  nomineeId: "",
  votes: 5,
  name: "",
  email: "",
  order: null,
};
let cats = [];
let nominees = [];
let editionId = "";
const nomineeOf = () => nominees.find((n) => n.id === S.nomineeId);
const categoryOf = () => cats.find((c) => c.id === S.categoryId);

export default async function vote(ctx, { params }) {
  const hero = pageHero({
    eyebrow: "Cast your vote",
    title: "Vote for your favourite",
    lead: `Each vote costs ${money(APP.voting.pricePerVote)}. Up to ${APP.voting.maxVotesPerPayment} votes per payment.`,
    crumbs: [{ label: "Vote" }],
  });
  const closed = stateGate(ctx, ["voting"], {
    "voting-closed": {
      icon: "fa-lock",
      title: "Voting has closed",
      text: "Thank you to everyone who voted. Winners will be announced soon.",
      actions: [{ label: "Back home", href: "index.html" }],
    },
    results: {
      icon: "fa-trophy",
      title: "Voting is over",
      text: "The winners have been announced.",
      actions: [{ label: "See the winners", href: "results.html" }],
    },
    default: {
      icon: "fa-hourglass-half",
      title: "Voting isn’t open yet",
      text: "Check back when voting opens — the countdown is on the home page.",
      actions: [{ label: "Back home", href: "index.html" }],
    },
  });
  if (closed) return html`${hero}${closed}`;

  editionId = ctx.occasion.id;
  [cats, nominees] = await Promise.all([
    getCategories(ctx.occasion.id),
    getNominees(ctx.occasion.id),
  ]);
  Object.assign(S, { step: 1, order: null });
  const pre = nominees.find((n) => n.id === params.get("nominee"));
  S.nomineeId = pre?.id ?? "";
  S.categoryId =
    pre?.categoryId ??
    (cats.some((c) => c.id === params.get("category"))
      ? params.get("category")
      : "");
  return html`${hero}${pageBody(html`<div id="voteApp"></div>`, {
    width: "52rem",
  })}`;
}

export function init(root) {
  const el = root.querySelector("#voteApp");
  if (el) render(el); // absent when the page is gated (voting not open)
}

const stepper = (current) =>
  html` <ol class="oga-steps" aria-label="Progress">
    ${["Nominee", "Votes", "Review"].map(
      (label, i) =>
        html`<li
          class="${i + 1 < current
            ? "is-done"
            : i + 1 === current
              ? "is-current"
              : ""}"
          ${i + 1 === current ? html`aria-current="step"` : ""}
        >
          ${i + 1}. ${label}
        </li>`,
    )}
  </ol>`;

function render(el) {
  window.scrollTo({ top: 0, behavior: "smooth" });
  const views = { 1: stepNominee, 2: stepVotes, 3: stepReview, 4: stepDone };
  mount(
    el,
    html`<div class="oga-formcard">
      ${S.step < 4 ? stepper(S.step) : ""}${views[S.step]()}
    </div>`,
  );
  ({ 1: bindNominee, 2: bindVotes, 3: bindReview, 4: bindDone })[S.step](el);
}

/* Step 1 — choose nominee */
function stepNominee() {
  const list = nominees.filter((n) => n.categoryId === S.categoryId);
  return html` <div class="row g-3 mb-4">
      <div class="col-md-5">
        ${field({
          label: "Category",
          name: "category",
          options: cats.map((c) => ({ value: c.id, label: c.name })),
          value: S.categoryId,
          placeholder: "Choose a category",
        })}
      </div>
      <div class="col-md-7">
        <label class="form-label" for="voteSearch">Search nominees</label>
        <input
          id="voteSearch"
          type="search"
          class="form-control"
          placeholder="Type a name"
          ${S.categoryId ? "" : "disabled"}
        />
      </div>
    </div>
    ${S.categoryId
      ? html`<div class="row g-3" id="voteList">
          ${list.map(
            (n) =>
              html` <div
                class="col-sm-6"
                data-pick-wrap
                data-name="${n.name.toLowerCase()}"
              >
                <button
                  type="button"
                  class="vote-pick ${n.id === S.nomineeId ? "is-selected" : ""}"
                  data-pick="${n.id}"
                  aria-pressed="${n.id === S.nomineeId}"
                >
                  ${avatar(n)}<span
                    ><strong>${n.name}</strong
                    ><small>${n.department ?? ""}</small></span
                  >
                </button>
              </div>`,
          )}${list.length
            ? ""
            : html`<div class="col-12">
                <div class="oga-empty mb-0">
                  No nominees in this category yet.
                </div>
              </div>`}
        </div>`
      : html`<div class="oga-empty mb-0">
          <i class="fa-solid fa-hand-pointer"></i>
          <p class="mb-0">Choose a category to see its nominees.</p>
        </div>`}
    <div class="d-flex justify-content-end mt-4">
      <button
        class="btn btn-gold btn-lg"
        id="toVotes"
        ${S.nomineeId ? "" : "disabled"}
      >
        Continue <i class="fa-solid fa-arrow-right ms-2"></i>
      </button>
    </div>`;
}

function bindNominee(el) {
  el.querySelector("#f-category").addEventListener("change", (e) => {
    S.categoryId = e.target.value;
    S.nomineeId = "";
    render(el);
  });
  el.querySelector("#voteSearch")?.addEventListener("input", (e) => {
    const q = e.target.value.trim().toLowerCase();
    el.querySelectorAll("[data-pick-wrap]").forEach((w) =>
      w.classList.toggle("d-none", Boolean(q) && !w.dataset.name.includes(q)),
    );
  });
  el.querySelector("#voteList")?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-pick]");
    if (!btn) return;
    S.nomineeId = btn.dataset.pick;
    el.querySelectorAll("[data-pick]").forEach((b) => {
      const on = b === btn;
      b.classList.toggle("is-selected", on);
      b.setAttribute("aria-pressed", on);
    });
    el.querySelector("#toVotes").disabled = false;
  });
  el.querySelector("#toVotes").addEventListener("click", () => {
    S.step = 2;
    render(el);
  });
}

/* Step 2 — votes + your details */
function stepVotes() {
  const max = APP.voting.maxVotesPerPayment;
  const quick = [...new Set([1, 5, 10, 20, max])].filter((n) => n <= max);
  return html` <p class="mb-4">
      Voting for <strong class="text-gold">${nomineeOf().name}</strong> ·
      ${categoryOf().name}
    </p>
    <form id="votesForm" novalidate>
      <label class="form-label" for="f-votes">How many votes?</label>
      <div class="oga-quick">
        ${quick.map(
          (n) =>
            html`<button type="button" class="oga-chip" data-quick="${n}">
              ${n}
            </button>`,
        )}
      </div>
      ${field({
        label: "",
        name: "votes",
        type: "number",
        value: S.votes,
        attrs: `min="1" max="${max}" inputmode="numeric"`,
      })}
      <div class="oga-total">
        <span>Total</span
        ><strong id="voteTotal">${money(orderTotal(S.votes))}</strong>
      </div>
      <div class="row">
        ${field({
          label: "Your name",
          name: "name",
          required: true,
          value: S.name,
          autocomplete: "name",
          col: "col-md-6",
        })}
        ${field({
          label: "Email",
          name: "email",
          type: "email",
          required: true,
          value: S.email,
          autocomplete: "email",
          col: "col-md-6",
          help: "We’ll send your receipt here.",
        })}
      </div>
      <div class="d-flex justify-content-between mt-4">
        <button type="button" class="btn btn-ghost" id="backToNominee">
          <i class="fa-solid fa-arrow-left me-2"></i>Back
        </button>
        <button type="submit" class="btn btn-gold btn-lg">
          Review <i class="fa-solid fa-arrow-right ms-2"></i>
        </button>
      </div>
    </form>`;
}

function bindVotes(el) {
  const form = el.querySelector("#votesForm");
  const input = form.elements.votes;
  const total = el.querySelector("#voteTotal");
  const sync = () => {
    const n = Number(input.value);
    total.textContent = money(Number.isInteger(n) && n > 0 ? orderTotal(n) : 0);
  };
  input.addEventListener("input", sync);
  el.querySelectorAll("[data-quick]").forEach((b) =>
    b.addEventListener("click", () => {
      input.value = b.dataset.quick;
      input.classList.remove("is-invalid");
      sync();
    }),
  );
  el.querySelector("#backToNominee").addEventListener("click", () => {
    S.step = 1;
    render(el);
  });
  bindForm(form, {
    schema: {
      votes: [rules.custom((v) => validateVotes(Number(v)))],
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
    },
    onSubmit(v) {
      Object.assign(S, {
        votes: Number(v.votes),
        name: v.name.trim(),
        email: v.email.trim(),
        step: 3,
      });
      render(el);
    },
  });
}

/* Step 3 — review + pay */
function stepReview() {
  const n = nomineeOf();
  return html` <h2 class="h4 mb-3">Review your order</h2>
    <dl class="oga-summary">
      <div>
        <dt>Nominee</dt>
        <dd>${n.name}</dd>
      </div>
      <div>
        <dt>Category</dt>
        <dd>${categoryOf().name}</dd>
      </div>
      <div>
        <dt>Votes</dt>
        <dd>${S.votes}</dd>
      </div>
      <div>
        <dt>Price per vote</dt>
        <dd>${money(APP.voting.pricePerVote)}</dd>
      </div>
      <div>
        <dt>Receipt to</dt>
        <dd>${S.email}</dd>
      </div>
    </dl>
    <div class="oga-total">
      <span>Total to pay</span><strong>${money(orderTotal(S.votes))}</strong>
    </div>
    <p class="small">
      Votes are counted once your payment is verified. Payments are final.
    </p>
    <div class="d-flex justify-content-between mt-4">
      <button class="btn btn-ghost" id="backToVotes">
        <i class="fa-solid fa-arrow-left me-2"></i>Back
      </button>
      <button class="btn btn-gold btn-lg" id="payBtn">
        <i class="fa-solid fa-lock me-2"></i>Pay ${money(orderTotal(S.votes))}
      </button>
    </div>`;
}

function bindReview(el) {
  el.querySelector("#backToVotes").addEventListener("click", () => {
    S.step = 2;
    render(el);
  });
  const pay = el.querySelector("#payBtn");
  pay.addEventListener("click", async () => {
    const label = pay.innerHTML;
    pay.disabled = true;
    pay.innerHTML =
      '<span class="spinner-border spinner-border-sm me-2"></span>Processing…';
    try {
      const order = await createOrder({
        nomineeId: S.nomineeId,
        categoryId: S.categoryId,
        editionId,
        votes: S.votes,
        name: S.name,
        email: S.email,
      });
      await checkout(order);
      S.order = await confirmOrder(order.reference);
      S.step = 4;
      render(el);
    } catch (err) {
      if (err.cancelled) notify.toast("info", err.userMessage);
      else await notify.failure(err);
      pay.disabled = false;
      pay.innerHTML = label;
    }
  });
}

/* Step 4 — done */
function stepDone() {
  const n = nomineeOf();
  return html` <div class="text-center py-3">
    <span class="oga-icon mb-3" style="width:4rem;height:4rem;font-size:1.8rem"
      ><i class="fa-solid fa-check"></i
    ></span>
    <h2 class="h3">Thank you for voting!</h2>
    <p>
      Your ${S.order.votes} vote${S.order.votes === 1 ? "" : "s"} for
      <strong class="text-gold">${n.name}</strong> ${S.order.votes === 1
        ? "has"
        : "have"}
      been recorded.
    </p>
    <p class="mb-1">Reference</p>
    <p class="oga-ticket__code fs-5">${S.order.reference}</p>
    <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
      <button class="btn btn-gold" id="voteAgain">Vote for someone else</button>
      <a class="btn btn-ghost" href="${ROOT}index.html">Back home</a>
    </div>
  </div>`;
}

function bindDone(el) {
  el.querySelector("#voteAgain").addEventListener("click", () => {
    Object.assign(S, { step: 1, nomineeId: "", order: null });
    render(el);
  });
}
