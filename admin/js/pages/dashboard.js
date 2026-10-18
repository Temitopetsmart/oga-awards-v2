import { ROOT } from "../../../shared/js/config/app.config.js";
import { html } from "../../../shared/js/core/dom.js";
import { money, number, formatDate } from "../../../shared/js/core/format.js";
import { store } from "../../../shared/js/core/store.js";
import { loadScript } from "../../../shared/js/core/load.js";
import { getHomeContext } from "../../../shared/js/services/event.service.js";
import "../../../shared/js/components/oga-countdown.js";
import { statCard } from "../components/stat-card.js";
import { statusBadge } from "../components/badges.js";
import { listNominees } from "../services/nominee.repo.js";
import { listCategories } from "../services/category.repo.js";
import { getAudit } from "../services/admin-store.js";

export const title = "Dashboard";
export const perm = "dashboard";
export const crumbs = ["Dashboard"];
const CHART_JS =
  "https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.js";

let chartData = { labels: [], values: [] };

export default async function dashboard() {
  const [ctx, nominees, cats] = await Promise.all([
    getHomeContext(),
    listNominees(),
    listCategories(),
  ]);
  const paidVotes = store
    .get("vote-orders", [])
    .filter((o) => o.status === "paid");
  const ticketOrders = store
    .get("ticket-orders", [])
    .filter((o) => o.status === "paid");
  const apps = store.get("committee-applications", []);
  const messages = store.get("contact-messages", []);
  const pending = nominees.filter((n) => n.status === "pending");

  const byCat = {};
  paidVotes.forEach((o) => {
    byCat[o.categoryId] = (byCat[o.categoryId] ?? 0) + o.votes;
  });
  chartData = {
    labels: cats.filter((c) => byCat[c.id]).map((c) => c.name),
    values: cats.filter((c) => byCat[c.id]).map((c) => byCat[c.id]),
  };

  const votes = paidVotes.reduce((s, o) => s + o.votes, 0);
  const activity = getAudit().slice(0, 6);

  return html` <div class="row g-3 mb-4">
      <div class="col-12">
        <div class="oga-stat">
          <span class="oga-icon"
            ><i class="fa-solid fa-flag-checkered"></i
          ></span>
          <span class="flex-grow-1"
            ><span class="oga-stat__label"
              >Public site is currently showing</span
            >
            <span class="oga-stat__value"
              >${ctx.occasion.name} ${ctx.occasion.edition} ·
              ${ctx.stateInfo.label}</span
            >
            ${ctx.countdown
              ? html`<span class="oga-stat__sub"
                  >${ctx.countdown.label}:
                  ${formatDate(ctx.countdown.at, {
                    dateStyle: "full",
                    timeStyle: "short",
                  })}</span
                >`
              : ""}</span
          >
          <a
            class="btn btn-ghost btn-sm"
            href="${ROOT}index.html"
            target="_blank"
            rel="noopener"
            >Open site</a
          >
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-sm-6 col-xl-3">
        ${statCard({
          icon: "fa-user-group",
          label: "Nominees",
          value: number(nominees.length),
          sub: `${pending.length} pending review`,
          href: `${ROOT}admin/nominees.html`,
        })}
      </div>
      <div class="col-sm-6 col-xl-3">
        ${statCard({
          icon: "fa-check-to-slot",
          label: "Paid votes",
          value: number(votes),
          sub:
            money(paidVotes.reduce((s, o) => s + o.amount, 0)) + " collected",
        })}
      </div>
      <div class="col-sm-6 col-xl-3">
        ${statCard({
          icon: "fa-ticket",
          label: "Tickets sold",
          value: number(ticketOrders.reduce((s, o) => s + o.qty, 0)),
          sub:
            money(ticketOrders.reduce((s, o) => s + o.amount, 0)) + " revenue",
        })}
      </div>
      <div class="col-sm-6 col-xl-3">
        ${statCard({
          icon: "fa-layer-group",
          label: "Categories",
          value: number(cats.length),
          sub: `${apps.length} committee applications · ${messages.length} messages`,
          href: `${ROOT}admin/categories.html`,
        })}
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <h2 class="card-title mb-0">Votes by category</h2>
          </div>
          <div class="card-body">
            ${chartData.values.length
              ? html`<div class="oga-chart">
                  <canvas
                    id="votesChart"
                    aria-label="Bar chart of paid votes by category"
                    role="img"
                  ></canvas>
                </div>`
              : html`<div class="oga-dt__empty">
                  <i class="fa-solid fa-chart-column"></i>No paid votes yet.
                  Votes cast on the public site will appear here.
                </div>`}
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div
            class="card-header d-flex justify-content-between align-items-center"
          >
            <h2 class="card-title mb-0">Awaiting review</h2>
            <a class="btn btn-ghost btn-sm" href="${ROOT}admin/nominees.html"
              >Open queue</a
            >
          </div>
          <div class="card-body">
            ${pending.length
              ? html`<ul class="oga-feed">
                  ${pending.slice(0, 5).map(
                    (n) =>
                      html`<li>
                        <span class="flex-grow-1"
                          ><strong>${n.name}</strong
                          ><time>${n.department ?? ""}</time></span
                        >${statusBadge(n.status)}
                      </li>`,
                  )}
                </ul>`
              : html`<div class="oga-dt__empty">
                  <i class="fa-solid fa-circle-check"></i>Nothing waiting —
                  you’re all caught up.
                </div>`}
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h2 class="card-title mb-0">Recent activity</h2>
          </div>
          <div class="card-body">
            ${activity.length
              ? html`<ul class="oga-feed">
                  ${activity.map(
                    (a) =>
                      html`<li>
                        <span
                          ><strong>${a.admin}</strong> ${a.action}${a.target
                            ? html` — ${a.target}`
                            : ""}
                          <time
                            >${formatDate(a.at, {
                              dateStyle: "medium",
                              timeStyle: "short",
                            })}</time
                          ></span
                        >
                      </li>`,
                  )}
                </ul>`
              : html`<div class="oga-dt__empty mb-0">
                  Actions you take in the admin will be listed here.
                </div>`}
          </div>
        </div>
      </div>
    </div>`;
}

export async function init(root) {
  const canvas = root.querySelector("#votesChart");
  if (!canvas) return;
  try {
    await loadScript(CHART_JS, () => window.Chart);
    const gold = "#d4af37";
    const muted = "#b9b6ac";
    const grid = "rgba(247,245,239,.08)";
    new window.Chart(canvas, {
      type: "bar",
      data: {
        labels: chartData.labels,
        datasets: [
          {
            label: "Paid votes",
            data: chartData.values,
            backgroundColor: gold,
            borderRadius: 6,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { color: muted }, grid: { display: false } },
          y: {
            beginAtZero: true,
            ticks: { color: muted, precision: 0 },
            grid: { color: grid },
          },
        },
      },
    });
  } catch (err) {
    console.error(err);
    canvas.replaceWith(
      Object.assign(document.createElement("p"), {
        textContent: "Chart unavailable right now.",
        className: "text-secondary mb-0",
      }),
    );
  }
}
