import { html } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { mountCategoryDropdown } from "../../../shared/js/components/category-dropdown.js";
import { money } from "../../../shared/js/core/format.js";
import {
  createNomineeEntry,
  resubmitNomineeEntry,
  switchNomineeEntry,
  getCategoryOptions,
} from "../../../shared/js/services/auth.service.js";
import { statusChip, card, emptyState, href } from "../ui.js";

export const title = "My entries";

export default function entries(p) {
  const {
    ctx,
    me,
    cat,
    mineHere,
    entries: all,
    catName,
    canApply,
    fee,
    categories,
    takenIds,
    maxCategories,
    readOnly,
  } = p;
  const here = readOnly
    ? [{ ...me, categoryName: cat?.name }]
    : mineHere.map((e) => ({ ...e, categoryName: catName(e) }));
  const others = all.filter((e) => e.occasionId !== ctx.occasion.id);
  const open = ctx.state === "nominations";

  return html` ${card(
    `${ctx.occasion.name} ${ctx.occasion.edition}`,
    here.length === 0
      ? emptyState(
          "fa-list-check",
          "No entry in this edition yet",
          canApply ? "Apply below to enter." : "Nominations are closed.",
        )
      : html`<div class="d-grid gap-3">
          ${here.map(
            (e) =>
              html`<div class="border rounded-3 p-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <strong>${e.categoryName ?? "Category"}</strong>${statusChip(
                    e.status,
                  )}
                  ${e.id === me.id && here.length > 1
                    ? html`<span class="pt-chip">Shown on Home</span>`
                    : ""}
                </div>
                ${!readOnly && here.length > 1
                  ? html`<p class="small text-secondary mb-0 mt-2">
                      ${e.isMain
                        ? "Your main entry: the other categories use its photo and bio unless you change them."
                        : e.photoCustom || e.bioCustom
                          ? `Has its own ${[e.photoCustom ? "photo" : "", e.bioCustom ? "bio" : ""].filter(Boolean).join(" and ")}.`
                          : "Uses your main photo and bio."}
                      ${open
                        ? html` <button
                            class="btn btn-link btn-sm p-0 align-baseline"
                            type="button"
                            data-edit="${e.id}"
                          >
                            Change photo or bio for this category
                          </button>`
                        : ""}
                    </p>`
                  : ""}
                ${e.status === "approved"
                  ? html`<p class="small text-secondary mb-0 mt-2">
                      You’re on the public list. Votes for each category are
                      counted separately.
                    </p>`
                  : ""}
                ${e.status === "pending"
                  ? html`<p class="small text-secondary mb-0 mt-2">
                      Our team is reviewing this entry. You’ll get an email when
                      there is a decision.
                    </p>`
                  : ""}
                ${e.status === "rejected"
                  ? html`<div
                      class="alert alert-warning mt-2 mb-0"
                      role="alert"
                    >
                      <strong>This entry needs changes.</strong>
                      <p class="mb-2 mt-1">
                        ${e.reviewNote
                          ? html`Reviewer’s note: ${e.reviewNote}`
                          : "Update your profile, then send it for review again."}
                      </p>
                      ${!readOnly && open
                        ? html`<button
                            class="btn btn-gold btn-sm"
                            data-resubmit="${e.id}"
                            type="button"
                          >
                            Send for review again
                          </button>`
                        : html`<span class="small"
                            >Nominations are closed, so this entry can’t be sent
                            again.</span
                          >`}
                    </div>`
                  : ""}
              </div>`,
          )}
        </div>`,
    { icon: "fa-list-check" },
  )}
  ${canApply
    ? html`<div class="mt-4">
        ${card(
          mineHere.length === 0
            ? `Apply for ${ctx.occasion.name} ${ctx.occasion.edition}`
            : "Enter more categories",
          html`<p class="small text-secondary">
              We reuse your account and details, so there is nothing to fill in
              again.
              ${fee > 0
                ? `Each category costs ${money(fee)}: choose two and you pay ${money(fee * 2)}, choose three and you pay ${money(fee * 3)}.`
                : "Entering more categories is free."}
              Each category is reviewed and voted on separately.
            </p>
            <form id="newEntryForm" novalidate>
              <label class="form-label" for="addHost"
                >Categories open to you</label
              >
              <div id="addHost">
                <div class="text-secondary small">Loading…</div>
              </div>
              <div
                class="invalid-feedback d-block"
                id="addError"
                role="alert"
              ></div>
              <div id="addSummary" class="small my-3" aria-live="polite"></div>
              <button class="btn btn-gold" type="submit" id="addBtn" disabled>
                Choose at least one category
              </button>
            </form>`,
          { icon: "fa-plus" },
        )}
      </div>`
    : ""}
  ${others.length
    ? html`<div class="mt-4">
        ${card(
          "Earlier editions",
          html`<p class="small text-secondary">
              Your account keeps a separate entry for each edition. Switching
              does not change or delete any of them.
            </p>
            <div class="d-grid gap-2">
              ${others.map(
                (e) =>
                  html`<div class="d-flex flex-wrap align-items-center gap-2">
                    <span>${e.occasionId}</span
                    ><span class="text-secondary">${catName(e) ?? ""}</span
                    >${statusChip(e.status)}${e.id === me.id
                      ? html`<span class="pt-chip">Shown on Home</span>`
                      : html`<button
                          class="btn btn-ghost btn-sm ms-auto"
                          data-switch="${e.id}"
                          type="button"
                        >
                          Show this entry
                        </button>`}
                  </div>`,
              )}
            </div>`,
          { icon: "fa-clock-rotate-left" },
        )}
      </div>`
    : ""}`;
}

export function init(root, p) {
  if (p.readOnly) return;
  root.querySelectorAll("[data-resubmit]").forEach((b) =>
    b.addEventListener("click", async () => {
      b.disabled = true;
      try {
        await resubmitNomineeEntry(b.dataset.resubmit);
        notify.toast("success", "Sent for review again");
        location.reload();
      } catch (err) {
        b.disabled = false;
        await notify.warning("Couldn’t send it", err.message);
      }
    }),
  );
  root.querySelectorAll("[data-switch]").forEach((b) =>
    b.addEventListener("click", () => {
      try {
        switchNomineeEntry(b.dataset.switch);
        location.reload();
      } catch (err) {
        notify.failure(err);
      }
    }),
  );
  root.querySelectorAll("[data-edit]").forEach((b) =>
    b.addEventListener("click", () => {
      try {
        switchNomineeEntry(b.dataset.edit);
        location.assign(href(p, "profile"));
      } catch (err) {
        notify.failure(err);
      }
    }),
  );
  const form = root.querySelector("#newEntryForm");
  if (!form) return;
  const host = form.querySelector("#addHost");
  const summary = form.querySelector("#addSummary");
  const btn = form.querySelector("#addBtn");
  const error = form.querySelector("#addError");
  let o = null;
  let dd = null;
  const refresh = () => {
    const n = dd?.values().length ?? 0;
    error.textContent = "";
    if (!n) {
      summary.textContent =
        o?.room === 0
          ? "You have entered the most categories allowed in this edition."
          : "";
      btn.textContent = "Choose at least one category";
      btn.disabled = true;
      return;
    }
    const fee = Number(o.fee) || 0;
    summary.textContent =
      fee > 0
        ? `${n} ${n === 1 ? "category" : "categories"} × ${money(fee)} = ${money(fee * n)}`
        : `${n} ${n === 1 ? "category" : "categories"}, no fee`;
    btn.textContent =
      fee > 0
        ? `Pay ${money(fee * n)} and apply`
        : `Enter ${n === 1 ? "this category" : `these ${n} categories`}`;
    btn.disabled = false;
  };
  getCategoryOptions({ occasionId: p.ctx.occasion.id })
    .then((r) => {
      o = r;
      if (!r.open || r.room === 0 || !r.categories.length) {
        host.innerHTML = `<p class="text-secondary mb-0">${r.room === 0 ? "You have entered the most categories allowed in this edition." : "There are no more categories open to you right now."}</p>`;
        btn.classList.add("d-none");
        return;
      }
      dd = mountCategoryDropdown(host, {
        categories: r.categories,
        multi: true,
        max: r.room,
        placeholder: `Choose up to ${r.room} ${r.room === 1 ? "category" : "categories"}`,
        onChange: refresh,
      });
      refresh();
    })
    .catch((err) => {
      host.innerHTML = `<p class="text-danger mb-0">${err.message ?? "We could not load the categories."}</p>`;
    });
  bindForm(form, {
    schema: {},
    async onSubmit() {
      const categoryIds = dd?.values() ?? [];
      if (categoryIds.length === 0) {
        error.textContent = "Choose at least one category.";
        throw new Error("Choose at least one category.");
      }
      const r = await createNomineeEntry({
        occasionId: p.ctx.occasion.id,
        categoryIds,
      });
      if (r.paymentRequired) {
        location.assign(r.paymentLink);
        await new Promise(() => {});
      }
      await notify.success(
        "Entered",
        categoryIds.length === 1
          ? "Your new entry is pending review. It starts with your main photo and bio, which you can change for each category."
          : "Your new entries are pending review. They start with your main photo and bio, which you can change for each category.",
      );
      location.reload();
    },
  });
}
