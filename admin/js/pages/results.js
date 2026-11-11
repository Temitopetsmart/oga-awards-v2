import { html } from "../../../shared/js/core/dom.js";
import { number } from "../../../shared/js/core/format.js";
import { downloadCsv } from "../../../shared/js/core/csv.js";
import { ROOT } from "../../../shared/js/config/app.config.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { avatar } from "../../../shared/js/components/cards.js";
import { listNominees } from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import {
  fetchResults,
  saveWinners,
  publishResults,
  retractResults,
} from "../services/vote.admin.api.js";
import { editionLabel } from "../services/edition.repo.js";

export const title = "Results";
export const perm = "results.view";
export const crumbs = ["Awards", "Results"];

export default async function results({ edition, can }) {
  const [nominees, cats, results] = await Promise.all([
    listNominees(),
    listCategories(),
    fetchResults(),
  ]);
  const tally = results.tally;
  const saved = results.winners;
  const pub = results.published ?? {};
  const locked = Boolean(pub.at); // published or scheduled: winners can't change until retracted
  const editable = can("results.save") && !locked;
  const canPublish = can("results.publish");

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
      const chosen = saved.find((w) => w.categoryId === c.id);
      const current =
        chosen?.nomineeId ?? (tie || !list[0]?.votes ? "" : list[0].id);
      const leaders = list
        .filter((n) => n.votes === (list[0]?.votes ?? 0) && n.votes > 0)
        .map((n) => n.id);
      return {
        c,
        list,
        total,
        tie,
        current,
        leaders,
        reason: chosen?.reason ?? "",
      };
    });

  const stateLine = !pub.at
    ? "Not published. Winners are hidden from the public until you publish them."
    : pub.live
      ? `Published ${new Date(pub.at).toLocaleString()}${pub.by ? ` by ${pub.by}` : ""}. Visible on the public site.`
      : `Scheduled for ${new Date(pub.at).toLocaleString()}. Hidden until then.`;

  return html` <div class="oga-banner">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Results for
        <strong>${editionLabel(edition)}</strong>:
        ${stateLine}${can("settings.view")
          ? html` (state: <a href="${ROOT}admin/settings">Settings</a>)`
          : ""}</span
      >
      <span class="d-flex flex-wrap gap-2"
        >${can("votes.export")
          ? html`<button class="btn btn-ghost btn-sm" id="exportBtn">
              <i class="fa-solid fa-file-csv me-1"></i>Export CSV
            </button>`
          : ""}
        ${editable
          ? html`<button class="btn btn-gold btn-sm" id="saveBtn">
              <i class="fa-solid fa-trophy me-1"></i>Save winners
            </button>`
          : ""}
        ${canPublish && !pub.at
          ? html`<button class="btn btn-gold btn-sm" id="publishBtn">
                <i class="fa-solid fa-bullhorn me-1"></i>Publish now
              </button>
              <button class="btn btn-ghost btn-sm" id="scheduleBtn">
                <i class="fa-solid fa-clock me-1"></i>Schedule
              </button>`
          : ""}
        ${canPublish && pub.at
          ? html`<button class="btn btn-ghost btn-sm" id="retractBtn">
              <i class="fa-solid fa-rotate-left me-1"></i>Retract
            </button>`
          : ""}</span
      >
    </div>
    ${sections.length
      ? sections.map(
          ({ c, list, total, tie, current, leaders, reason }) =>
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
                    data-leaders="${leaders.join(",")}"
                    data-tie="${tie ? "1" : ""}"
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
              <div class="px-3 pb-2" data-reason-row="${c.id}" hidden>
                <label class="form-label small mb-1" for="r-${c.id}"
                  >Why this winner? (required when they are not the vote leader,
                  or the top spot is tied)</label
                >
                <input
                  class="form-control form-control-sm"
                  id="r-${c.id}"
                  data-reason="${c.id}"
                  maxlength="255"
                  value="${reason}"
                  ${editable ? "" : "disabled"}
                />
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
  const [nominees, cats, results] = await Promise.all([
    listNominees(),
    listCategories(),
    fetchResults(),
  ]);
  const tally = results.tally;
  const choices = () =>
    [...root.querySelectorAll("[data-winner]")].map((s) => ({
      categoryId: s.dataset.winner,
      nomineeId: s.value,
      reason:
        root.querySelector(`[data-reason="${s.dataset.winner}"]`)?.value ?? "",
    }));

  // Show the "why" box only when the choice is not simply the vote leader.
  const syncReasons = () => {
    for (const s of root.querySelectorAll("[data-winner]")) {
      const leaders = (s.dataset.leaders || "").split(",").filter(Boolean);
      const needs =
        Boolean(s.value) &&
        leaders.length > 0 &&
        (!leaders.includes(s.value) || s.dataset.tie === "1");
      const row = root.querySelector(`[data-reason-row="${s.dataset.winner}"]`);
      if (row) row.hidden = !needs;
    }
  };
  root.addEventListener("change", (e) => {
    if (e.target.matches?.("[data-winner]")) syncReasons();
  });
  syncReasons();

  const save = async () => {
    const rows = choices()
      .filter((r) => r.nomineeId)
      .map((r) => ({ ...r, reason: r.reason.trim() }));
    await saveWinners(rows);
    return rows;
  };

  root.querySelector("#saveBtn")?.addEventListener("click", async () => {
    const count = choices().filter((r) => r.nomineeId).length;
    if (
      !(await notify.confirm({
        title: "Save these winners?",
        text: `${count} categor${count === 1 ? "y" : "ies"} will have a winner. Saving does not make them public: use Publish when you are ready.`,
        confirmText: "Save winners",
      }))
    )
      return;
    try {
      await save();
    } catch (err) {
      await notify.warning("Couldn’t save the winners", err.message);
      return;
    }
    notify.toast("success", "Winners saved");
  });

  const publish = async (at) => {
    try {
      await save(); // what is on screen is what gets published
      let r;
      try {
        r = await publishResults({ at });
      } catch (err) {
        if (!/no winner/i.test(err.message)) throw err;
        if (
          !(await notify.confirm({
            title: "Some categories have no winner",
            text: `${err.message} Publish anyway and leave them out?`,
            confirmText: "Publish anyway",
            danger: true,
          }))
        )
          return;
        r = await publishResults({ at, allowMissing: true });
      }
      if (r.warning) await notify.warning("Published, but…", r.warning);
      notify.toast("success", at ? "Results scheduled" : "Results published");
      window.location.reload();
    } catch (err) {
      await notify.warning("Couldn’t publish", err.message);
    }
  };

  root.querySelector("#publishBtn")?.addEventListener("click", async () => {
    if (
      !(await notify.confirm({
        title: "Publish the results now?",
        text: "The winners become public immediately. You can retract them afterwards, but people may already have seen them.",
        confirmText: "Publish now",
        danger: true,
      }))
    )
      return;
    await publish(null);
  });

  root.querySelector("#scheduleBtn")?.addEventListener("click", async () => {
    const done = await notify.prompt({
      title: "Schedule the reveal",
      text: "Date and time the winners become public (your local time), for example 2026-12-05 19:00.",
      placeholder: "YYYY-MM-DD HH:MM",
      confirmText: "Schedule",
      autocapitalize: "off",
      save: async (value) => {
        const when = new Date(value.replace(" ", "T"));
        if (Number.isNaN(when.getTime()))
          throw new Error("Use the format 2026-12-05 19:00.");
        if (when.getTime() < Date.now())
          throw new Error("That time has already passed.");
        await save();
        try {
          await publishResults({ at: when.toISOString() });
        } catch (err) {
          if (!/no winner/i.test(err.message)) throw err;
          await publishResults({ at: when.toISOString(), allowMissing: true });
        }
      },
    });
    if (done) {
      notify.toast("success", "Results scheduled");
      window.location.reload();
    }
  });

  root.querySelector("#retractBtn")?.addEventListener("click", async () => {
    const done = await notify.prompt({
      title: "Retract the results?",
      text: "They disappear from the public site at once. The reason is kept in the activity log.",
      placeholder: "Why are you retracting them?",
      confirmText: "Retract",
      autocapitalize: "sentences",
      save: (reason) => retractResults(reason),
    });
    if (done) {
      notify.toast("success", "Results retracted");
      window.location.reload();
    }
  });

  root.querySelector("#exportBtn")?.addEventListener("click", () => {
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
  });
}