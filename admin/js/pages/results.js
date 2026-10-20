import { html } from "../../../shared/js/core/dom.js";
import { number } from "../../../shared/js/core/format.js";
import { store } from "../../../shared/js/core/store.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { listNominees } from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import { collection, logAudit } from "../services/admin-store.js";
import { scoped } from "../services/scope.js";
import { editionLabel } from "../services/edition.repo.js";

export const title = "Results";
export const perm = "results.view";
export const crumbs = ["Awards", "Results"];
const winners = collection("winners", { seed: "winners", prefix: "WIN-" });

export default async function results({ edition, can }) {
  const [nominees, cats, published] = await Promise.all([
    listNominees(),
    listCategories(),
    winners.all(),
  ]);
  const tally = {};
  scoped(store.get("vote-orders", []))
    .filter((o) => o.status === "paid")
    .forEach((o) => {
      tally[o.nomineeId] = (tally[o.nomineeId] ?? 0) + o.votes;
    });
  const editable = can("results.save");

  const sections = cats
    .filter((c) => c.active !== false)
    .map((c) => {
      const list = nominees
        .filter((n) => n.categoryId === c.id && n.status === "approved")
        .map((n) => ({ ...n, votes: tally[n.id] ?? 0 }))
        .sort((a, b) => b.votes - a.votes);
      const total = list.reduce((s, n) => s + n.votes, 0);
      const tie =
        list.length > 1 && list[0].votes > 0 && list[0].votes === list[1].votes;
      const current =
        published.find(
          (w) => w.categoryId === c.id && w.occasionId === edition.id,
        )?.nomineeId ?? (tie || !list[0]?.votes ? "" : list[0].id);
      return { c, list, total, tie, current };
    });

  return html` <div class="oga-banner">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Winners for
        <strong>${editionLabel(edition)}</strong> appear publicly when that
        edition’s state is “Winners
        announced”${can("settings.view")
          ? html` (change it in
              <a href="${ROOT}admin/settings.html">Settings</a>)`
          : ""}.</span
      >
      <span class="d-flex gap-2"
        >${can("votes.export")
          ? html`<button class="btn btn-ghost btn-sm" id="exportBtn">
              <i class="fa-solid fa-file-csv me-1"></i>Export CSV
            </button>`
          : ""}
        ${editable
          ? html`<button class="btn btn-gold btn-sm" id="saveBtn">
              <i class="fa-solid fa-trophy me-1"></i>Save winners
            </button>`
          : ""}</span
      >
    </div>
    ${sections.length
      ? sections.map(
          ({ c, list, total, tie, current }) =>
            html` <div class="card mb-3 oga-result" data-cat="${c.id}">
              <div
                class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
              >
                <h2 class="card-title mb-0">
                  ${c.name}
                  ${tie
                    ? html`<span class="oga-status oga-status--warning ms-2"
                        >Tie</span
                      >`
                    : ""}
                </h2>
                <div class="d-flex align-items-center gap-2">
                  <label class="small text-secondary" for="w-${c.id}"
                    >Winner</label
                  >
                  <select
                    class="form-select form-select-sm"
                    id="w-${c.id}"
                    data-winner="${c.id}"
                    ${editable ? "" : "disabled"}
                  >
                    <option value="">— not chosen —</option>
                    ${list.map(
                      (n) =>
                        html`<option
                          value="${n.id}"
                          ${n.id === current ? "selected" : ""}
                        >
                          ${n.name}
                        </option>`,
                    )}
                  </select>
                </div>
              </div>
              ${list.length
                ? html`<div class="table-responsive">
                    <table class="table oga-table">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>Nominee</th>
                          <th>Votes</th>
                          <th style="width:30%">Share</th>
                        </tr>
                      </thead>
                      <tbody>
                        ${list.map(
                          (n, i) =>
                            html`<tr>
                              <td class="text-secondary">${i + 1}</td>
                              <td>
                                <div class="oga-person">
                                  ${avatar(n)}
                                  <div>
                                    <strong>${n.name}</strong
                                    ><small>${n.department ?? ""}</small>
                                  </div>
                                </div>
                              </td>
                              <td>${number(n.votes)}</td>
                              <td>
                                <div
                                  class="oga-bar"
                                  role="img"
                                  aria-label="${total
                                    ? Math.round((n.votes / total) * 100)
                                    : 0} percent"
                                >
                                  <span
                                    style="width:${total
                                      ? (n.votes / total) * 100
                                      : 0}%"
                                  ></span>
                                </div>
                              </td>
                            </tr>`,
                        )}
                      </tbody>
                    </table>
                  </div>`
                : html`<div class="oga-dt__empty">
                    No approved nominees in this category.
                  </div>`}
            </div>`,
        )
      : html`<div class="oga-dt__empty card py-5">
          <i class="fa-solid fa-trophy"></i>No categories yet in this edition.
        </div>`}`;
}

export async function init(root, { admin, edition }) {
  const [nominees, cats] = await Promise.all([
    listNominees(),
    listCategories(),
  ]);
  const choices = () =>
    [...root.querySelectorAll("[data-winner]")].map((s) => ({
      categoryId: s.dataset.winner,
      nomineeId: s.value,
    }));

  root.querySelector("#saveBtn")?.addEventListener("click", async () => {
    const rows = choices().filter((r) => r.nomineeId);
    if (
      !(await notify.confirm({
        title: "Save these winners?",
        text: `${rows.length} categor${rows.length === 1 ? "y" : "ies"} will have a winner. They show on the public site once the state is “Winners announced”.`,
        confirmText: "Save winners",
      }))
    )
      return;
    const others = (await winners.all()).filter(
      (w) => w.occasionId !== edition.id,
    ); // other editions are never touched
    await winners.replace([
      ...others,
      ...rows.map((r, i) => ({
        id: `WIN-${edition.id}-${i + 1}`,
        occasionId: edition.id,
        ...r,
      })),
    ]);
    logAudit(admin, "Saved winners", `${rows.length} categories`);
    notify.toast("success", "Winners saved");
  });

  root.querySelector("#exportBtn")?.addEventListener("click", () => {
    const tally = {};
    scoped(store.get("vote-orders", []))
      .filter((o) => o.status === "paid")
      .forEach((o) => {
        tally[o.nomineeId] = (tally[o.nomineeId] ?? 0) + o.votes;
      });
    const rows = nominees
      .filter((n) => n.status === "approved")
      .map((n) => ({
        category: cats.find((c) => c.id === n.categoryId)?.name ?? "",
        nominee: n.name,
        votes: tally[n.id] ?? 0,
        winner: choices().some((x) => x.nomineeId === n.id) ? "Yes" : "",
      }));
    downloadCsv(
      "oga-results.csv",
      rows.sort(
        (a, b) => a.category.localeCompare(b.category) || b.votes - a.votes,
      ),
      [
        { label: "Category", key: "category" },
        { label: "Nominee", key: "nominee" },
        { label: "Votes", key: "votes" },
        { label: "Winner", key: "winner" },
      ],
    );
    logAudit(admin, "Exported results");
  });
}
