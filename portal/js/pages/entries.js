import { html } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import {
  categoryPicker,
  pickedCategories,
  bindCategoryPicker,
} from "../../../shared/js/components/category-picker.js";
import {
  createNomineeEntry,
  resubmitNomineeEntry,
  switchNomineeEntry,
  currentNominee,
} from "../../../shared/js/services/auth.service.js";
import { statusChip, card, emptyState } from "../ui.js";

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
    feeDue,
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
            : "Enter another category",
          html`<p class="small text-secondary">
              ${mineHere.length === 0
                ? "We’ll reuse your account and profile details, but create a new entry for this edition."
                : "Same profile and photo, no extra registration fee. Each category is reviewed and voted on separately."}${feeDue
                ? " The registration fee is paid once, however many categories you pick."
                : ""}
            </p>
            <form id="newEntryForm" novalidate>
              ${categoryPicker({
                categories,
                taken: takenIds,
                max: maxCategories,
              })}
              <button class="btn btn-gold" type="submit">
                ${mineHere.length === 0
                  ? "Create edition entry"
                  : "Enter the selected categories"}
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
  const form = root.querySelector("#newEntryForm");
  if (!form) return;
  bindCategoryPicker(form, {
    max: p.maxCategories,
    taken: p.takenIds,
    level: currentNominee()?.accountLevel ?? currentNominee()?.level ?? "",
  });
  bindForm(form, {
    schema: {},
    async onSubmit(values, formEl) {
      const categoryIds = pickedCategories(formEl);
      if (categoryIds.length === 0)
        throw new Error("Choose at least one category.");
      const r = await createNomineeEntry({
        occasionId: p.ctx.occasion.id,
        categoryIds,
      });
      if (r.paymentRequired) {
        location.assign(r.paymentLink);
        await new Promise(() => {});
      }
      await notify.success(
        "Entry created",
        "Your new entry is pending review. Your profile and photo are shared by all your categories.",
      );
      location.reload();
    },
  });
}
