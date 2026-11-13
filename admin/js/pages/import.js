import { html, toHTML } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { number } from "../../../shared/js/core/format.js";
import { importEdition } from "../services/import.api.js";

export const title = "Import a past edition";
export const perm = "import.run";
export const crumbs = ["Administration", "Import a past edition"];

const TEMPLATE = {
  edition: {
    slug: "oga-9",
    name: "OGITECH Student Awards",
    label: "9.0",
    year: 2025,
    status: "completed",
    ceremony: "2025-12-06T18:00:00+01:00",
    tagline: "",
  },
  categories: [
    { name: "Face of OGITECH", description: "" },
    { name: "Best Developer", description: "" },
  ],
  nominees: [
    {
      name: "Example Nominee",
      department: "Software and Web Development",
      level: "HND II",
      category: "Face of OGITECH",
      bio: "",
      votes: 1200,
      winner: true,
    },
    {
      name: "Another Nominee",
      department: "Law",
      level: "ND I",
      category: "Face of OGITECH",
      bio: "",
      votes: 900,
    },
  ],
};

export default async function importPage() {
  return html` <div class="oga-banner" role="note">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Bring a past edition into
        the system as history: its categories, nominees, final vote counts and
        winners. It is added as a <strong>completed</strong> edition, never over
        an existing one. <strong>Always check first.</strong> Emails, phone
        numbers and photos are not imported (add photos afterwards).</span
      >
    </div>
    <div class="card">
      <div
        class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"
      >
        <h2 class="card-title mb-0">Import file</h2>
        <button class="btn btn-ghost btn-sm" id="templateBtn" type="button">
          <i class="fa-solid fa-download me-1"></i>Download a template
        </button>
      </div>
      <div class="card-body">
        <label class="form-label" for="importFile">JSON file</label>
        <input
          class="form-control mb-3"
          type="file"
          id="importFile"
          accept=".json,application/json"
        />
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button class="btn btn-gold" id="checkBtn" type="button" disabled>
            Check the file
          </button>
          <button class="btn btn-ghost" id="importBtn" type="button" disabled>
            Import
          </button>
        </div>
        <div id="importResult" aria-live="polite"></div>
      </div>
    </div>`;
}

export function init(root, { edition }) {
  let payload = null;
  const out = root.querySelector("#importResult");
  const checkBtn = root.querySelector("#checkBtn");
  const importBtn = root.querySelector("#importBtn");

  root.querySelector("#templateBtn").addEventListener("click", () => {
    const a = document.createElement("a");
    a.href = URL.createObjectURL(
      new Blob([JSON.stringify(TEMPLATE, null, 2)], {
        type: "application/json",
      }),
    );
    a.download = "import-template.json";
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1500);
  });

  root.querySelector("#importFile").addEventListener("change", async (e) => {
    importBtn.disabled = true;
    out.replaceChildren();
    payload = null;
    const file = e.target.files?.[0];
    if (!file) return void (checkBtn.disabled = true);
    if (file.size > 5 * 1024 * 1024) {
      out.innerHTML = toHTML(
        html`<div class="alert alert-danger">
          That file is too large (maximum 5 MB).
        </div>`,
      );
      checkBtn.disabled = true;
      return;
    }
    try {
      payload = JSON.parse(await file.text());
      checkBtn.disabled = false;
    } catch {
      out.innerHTML = toHTML(
        html`<div class="alert alert-danger">
          That file is not valid JSON. Download the template to see the shape.
        </div>`,
      );
      checkBtn.disabled = true;
    }
  });

  const show = (r, done) => {
    out.innerHTML = toHTML(
      html` ${r.errors?.length
        ? html`<div class="alert alert-danger">
            <strong>Fix these first (${r.errors.length}):</strong>
            <ul class="mb-0">
              ${r.errors.map((m) => html`<li>${m}</li>`)}
            </ul>
          </div>`
        : ""}
      ${r.summary
        ? html`<div class="alert alert-${done ? "success" : "info"}">
            <strong
              >${done ? "Imported" : "Ready to import"}:
              ${r.summary.edition}</strong
            ><br />
            ${number(r.summary.categories)} categories ·
            ${number(r.summary.nominees)} nominees ·
            ${number(r.summary.winners)} winners · ${number(r.summary.votes)}
            votes
          </div>`
        : ""}
      ${(r.notes ?? []).length
        ? html`<ul class="small text-secondary">
            ${r.notes.map((m) => html`<li>${m}</li>`)}
          </ul>`
        : ""}`,
    );
  };

  const call = async (dryRun) => {
    try {
      return await importEdition(edition.id, payload, dryRun);
    } catch (err) {
      if (Array.isArray(err.data?.errors)) {
        show(err.data, false);
        return null;
      }
      out.innerHTML = toHTML(
        html`<div class="alert alert-danger">${err.message}</div>`,
      );
      return null;
    }
  };

  checkBtn.addEventListener("click", async () => {
    importBtn.disabled = true;
    const r = await call(true);
    if (!r) return;
    show(r, false);
    importBtn.disabled = !(r.ok && r.summary);
  });

  importBtn.addEventListener("click", async () => {
    if (
      !(await notify.confirm({
        title: "Import this edition?",
        text: "It is added as a completed edition and its winners become public. This cannot be undone from here.",
        confirmText: "Import",
        danger: true,
      }))
    )
      return;
    importBtn.disabled = true;
    const r = await call(false);
    if (!r) return;
    show(r, true);
    notify.toast("success", "Edition imported");
  });
}
