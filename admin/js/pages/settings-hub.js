import { html, esc } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  compileMatric,
  normalizeMatric,
} from "../../../shared/js/core/matric.js";
import {
  saveSettings,
  testGatewayKey,
  previewPromotion,
  applyPromotion,
} from "../services/settings.api.js";

/**
 * Settings hub (Round 2, phase 2): branding, levels, departments, matric format and payment secrets.
 * Used by settings.js: hubCards(s, { editable, isSuper }) and initHub(root, { editable, isSuper }, s).
 */

const card = (heading, intro, body, id) =>
  html`<div class="card mb-4" id="${id}">
    <div class="card-header"><h2 class="card-title mb-0">${heading}</h2></div>
    <div class="card-body">
      <p class="text-secondary">${intro}</p>
      ${body}
    </div>
  </div>`;

const input = (
  label,
  name,
  value,
  { col = "col-md-4", type = "text", ph = "", help = "" } = {},
) =>
  html`<div class="${col} mb-3">
    <label class="form-label" for="hub-${name}">${label}</label>
    <input
      class="form-control"
      id="hub-${name}"
      name="${name}"
      type="${type}"
      value="${value ?? ""}"
      placeholder="${ph}"
      autocomplete="off"
    />
    ${help ? html`<div class="form-text">${help}</div>` : ""}
  </div>`;

export function hubCards(s, { editable, isSuper }) {
  const lock = editable ? "" : "disabled";
  const save = (id, label) =>
    editable
      ? html`<button class="btn btn-gold" type="submit" id="${id}">
          ${label}
        </button>`
      : "";
  const brand = s.brand ?? {};
  const secrets = Object.fromEntries((s.secrets ?? []).map((x) => [x.key, x]));

  const secretRow = (key, field, label, help) => {
    const st = secrets[key];
    const saved = st?.saved;
    return html`<div class="col-12 mb-3">
      <label class="form-label" for="hub-${field}">${label}</label>
      <div class="d-flex align-items-center gap-2 mb-1">
        <span class="badge ${saved ? "text-bg-success" : "text-bg-secondary"}"
          >${saved ? `Saved ${st.updatedAt ?? ""}` : "Not saved here"}</span
        >
        ${saved && isSuper
          ? html`<div class="form-check mb-0">
              <input
                class="form-check-input"
                type="checkbox"
                id="hub-clear-${field}"
                data-clear="${field}"
              /><label class="form-check-label small" for="hub-clear-${field}"
                >Remove the saved value</label
              >
            </div>`
          : ""}
      </div>
      <input
        class="form-control"
        id="hub-${field}"
        name="${field}"
        type="password"
        autocomplete="new-password"
        placeholder="${saved
          ? "Leave blank to keep the saved value"
          : "Paste the value"}"
        ${isSuper ? "" : "disabled"}
      />
      <div class="form-text">${help}</div>
    </div>`;
  };

  return html`
    ${card(
      "Branding",
      "The site name used in the header, footer and email footers. Colours and logo are managed with the Content manager.",
      html`<form id="brandForm" novalidate>
        <fieldset ${lock}>
          <div class="row">
            ${input("Site name", "brandName", brand.name ?? "OGA Awards", {
              required: true,
            })}
            ${input(
              "Organisation",
              "brandOrg",
              brand.org ?? "OGITECH Student Awards",
            )}
            ${input("Tagline", "brandTagline", brand.tagline ?? "")}
          </div>
          ${save("brandSave", "Save branding")}
        </fieldset>
      </form>`,
      "cardBrand",
    )}
    ${card(
      "Levels and departments",
      "These lists feed every registration form. A name can’t be renamed (records already use it); switch it off instead. Switched-off names are hidden from new registrations. For levels, “Matric” says whether students at that level must give a matric number (staff have none).",
      html`<div class="row">
        <div class="col-lg-6">
          <h3 class="h6">Levels</h3>
          <div id="hub-levels"></div>
        </div>
        <div class="col-lg-6">
          <h3 class="h6">Departments</h3>
          <div id="hub-departments"></div>
        </div>
      </div>`,
      "cardLists",
    )}
    ${card(
      "Level progression",
      "Choose which level each level moves to when the new session starts, save it, preview it, then move the students. Nominees cannot change their own level: this is how it changes. Entries from past editions keep the level they were made with.",
      html`<div id="hub-progression"></div>`,
      "cardProgression",
    )}
    ${card(
      "Matric number format",
      "Write a pattern per student type. # = a digit, @ = a letter, anything else is typed as-is. Add {n} or {n,m} after # or @ to repeat it. Example: @{3,5}/#{2}/#{4} matches HSWF/24/0010. The field itself is added to the forms in a later phase.",
      html`<form id="matricForm" novalidate>
        <fieldset ${lock}>
          <div class="row">
            ${input("Maximum length", "matricMax", s.matric?.maxLength ?? 13, {
              type: "number",
              col: "col-md-3",
            })}
          </div>
          <div id="hub-matric-formats"></div>
          <div class="row align-items-end mt-3">
            ${input("Try a matric number", "matricTry", "", {
              col: "col-md-4",
              ph: "HSWF/24/0010",
            })}
            <div class="col-md-8 mb-3">
              <span id="hub-matric-result" class="small text-secondary"></span>
            </div>
          </div>
          ${save("matricSave", "Save matric format")}
        </fieldset>
      </form>`,
      "cardMatric",
    )}
    ${card(
      "Payment secrets",
      "Flutterwave keys are encrypted before they are saved and can never be read back. A value saved here is used instead of config.php. Only a Super admin can change them.",
      html`<form id="secretsForm" novalidate>
        <div class="row">
          ${secretRow(
            "flutterwave.secret_key",
            "flutterwaveSecretKey",
            "Flutterwave secret key",
            "Starts with FLWSECK. Use the TEST key while testing and the LIVE key at go-live.",
          )}
          ${secretRow(
            "flutterwave.webhook_hash",
            "flutterwaveWebhookHash",
            "Flutterwave webhook secret hash",
            "The same value you typed as the secret hash in your Flutterwave dashboard webhook settings.",
          )}
        </div>
        ${isSuper
          ? html`<button class="btn btn-gold" type="submit" id="secretsSave">
                Save secrets
              </button>
              <button class="btn btn-ghost ms-2" type="button" id="secretsTest">
                Test the secret key
              </button>
              <span id="hub-secret-test" class="ms-2 small"></span>`
          : html`<div class="oga-banner oga-banner--warn" role="note">
              <span
                ><i class="fa-solid fa-lock me-2"></i>Only a Super admin can
                change these.</span
              >
            </div>`}
      </form>`,
      "cardSecrets",
    )}
  `;
}

/** A small editable, re-orderable list of { name, active, requiresMatric }. */
function listEditor(host, items, { maxName, editable, matric = false }) {
  let rows = items.map((x) => ({
    requiresMatric: true,
    ...x,
    saved: true,
  }));

  const draw = () => {
    host.innerHTML = `
      <ul class="list-group mb-2">
        ${
          rows
            .map(
              (
                r,
                i,
              ) => `<li class="list-group-item d-flex align-items-center gap-2" data-i="${i}">
              <span class="flex-grow-1 ${r.active ? "" : "text-secondary text-decoration-line-through"}">${esc(r.name)}</span>
              ${
                editable
                  ? `
              ${matric ? `<div class="form-check form-switch mb-0" title="Students at this level must give a matric number"><input class="form-check-input" type="checkbox" role="switch" data-act="matric" ${r.requiresMatric ? "checked" : ""} aria-label="Needs a matric number"><label class="form-check-label small text-secondary">Matric</label></div>` : ""}
              <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-act="toggle" ${r.active ? "checked" : ""} aria-label="Active"></div>
              <button type="button" class="btn btn-sm btn-ghost" data-act="up" ${i === 0 ? "disabled" : ""} aria-label="Move up"><i class="fa-solid fa-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-ghost" data-act="down" ${i === rows.length - 1 ? "disabled" : ""} aria-label="Move down"><i class="fa-solid fa-arrow-down"></i></button>
              ${r.saved ? "" : `<button type="button" class="btn btn-sm btn-ghost" data-act="remove" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>`}`
                  : ""
              }
            </li>`,
            )
            .join("") ||
          `<li class="list-group-item text-secondary">Nothing here yet.</li>`
        }
      </ul>
      ${
        editable
          ? `<div class="input-group mb-2"><input class="form-control" data-new maxlength="${maxName}" placeholder="Add a name"><button type="button" class="btn btn-ghost" data-act="add">Add</button></div>
      <button type="button" class="btn btn-gold btn-sm" data-act="save">Save</button>`
          : ""
      }`;
  };

  draw();

  return {
    host,
    rows: () =>
      rows.map(({ name, active, requiresMatric }) => ({
        name,
        active,
        requiresMatric,
      })),
    mark(list) {
      rows = list.map((x) => ({
        requiresMatric: true,
        ...x,
        saved: true,
      }));
      draw();
    },
    handle(e, onSave) {
      const btn = e.target.closest("[data-act]");
      if (!btn) return;

      const li = btn.closest("li");
      const i = li ? Number(li.dataset.i) : -1;
      const act = btn.dataset.act;

      if (act === "toggle") rows[i].active = btn.checked;
      else if (act === "matric") rows[i].requiresMatric = btn.checked;
      else if (act === "up" && i > 0)
        [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]];
      else if (act === "down" && i < rows.length - 1)
        [rows[i + 1], rows[i]] = [rows[i], rows[i + 1]];
      else if (act === "remove") rows.splice(i, 1);
      else if (act === "add") {
        const inp = host.querySelector("[data-new]");
        const name = inp.value.replace(/\s+/g, " ").trim();
        if (!name) return;

        if (rows.some((r) => r.name.toLowerCase() === name.toLowerCase()))
          return notify.toast("error", "That name is already in the list");

        rows.push({
          name,
          active: true,
          requiresMatric: true,
          saved: false,
        });
      } else if (act === "save") return onSave();

      draw();
    },
  };
}

export function initHub(root, { editable, isSuper }, s) {
  const fail = (e) =>
    notify.error("Could not save", e?.message ?? "Please try again.");

  // ---- branding
  root.querySelector("#brandForm")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const f = e.target.elements;

    if (!f.brandName.value.trim())
      return notify.toast("error", "Enter the site name");

    try {
      await saveSettings("brand", {
        name: f.brandName.value.trim(),
        org: f.brandOrg.value.trim(),
        tagline: f.brandTagline.value.trim(),
      });
      notify.toast("success", "Branding saved");
    } catch (err) {
      fail(err);
    }
  });

  // ---- levels and departments
  for (const [section, max] of [
    ["levels", 30],
    ["departments", 120],
  ]) {
    const host = root.querySelector(`#hub-${section}`);
    const ed = listEditor(host, s[section] ?? [], {
      maxName: max,
      editable,
      matric: section === "levels",
    });

    const save = async () => {
      try {
        const r = await saveSettings(section, ed.rows());
        ed.mark(r.items ?? ed.rows());
        notify.toast(
          "success",
          `${section === "levels" ? "Levels" : "Departments"} saved`,
        );
      } catch (err) {
        fail(err);
      }
    };

    host.addEventListener("click", (e) => ed.handle(e, save));
    host.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && e.target.matches("[data-new]")) {
        e.preventDefault();
        host.querySelector('[data-act="add"]').click();
      }
    });
  }

  // ---- matric
  const mHost = root.querySelector("#hub-matric-formats");
  const mForm = root.querySelector("#matricForm");
  let formats = (s.matric?.formats ?? []).map((f) => ({ ...f }));

  const readFormats = () => formats;
  const config = () => ({
    maxLength: Number(mForm.elements.matricMax.value),
    formats: readFormats(),
  });

  const drawFormats = () => {
    mHost.innerHTML =
      formats
        .map(
          (f, i) => `<div class="row g-2 mb-2" data-i="${i}">
          <div class="col-md-3"><input class="form-control" data-f="label" value="${esc(f.label)}" placeholder="Name, e.g. Full-time" ${editable ? "" : "disabled"}></div>
          <div class="col-md-4"><input class="form-control" data-f="pattern" value="${esc(f.pattern)}" placeholder="@{3,5}/#{2}/#{4}" ${editable ? "" : "disabled"}></div>
          <div class="col-md-3"><input class="form-control" data-f="example" value="${esc(f.example ?? "")}" placeholder="Example, e.g. HSWF/24/0010" ${editable ? "" : "disabled"}></div>
          <div class="col-md-2">${editable ? `<button type="button" class="btn btn-ghost" data-rm="${i}" aria-label="Remove format"><i class="fa-solid fa-xmark"></i></button>` : ""}</div>
        </div>`,
        )
        .join("") +
      (editable
        ? `<button type="button" class="btn btn-ghost btn-sm" id="hub-add-format">Add a format</button>`
        : "");
  };

  const tryIt = () => {
    const out = root.querySelector("#hub-matric-result");
    const v = mForm.elements.matricTry.value;

    if (!v.trim()) return (out.textContent = "");

    try {
      const ok = normalizeMatric(v, config());
      out.className = `small ${ok ? "text-success" : "text-danger"}`;
      out.textContent = ok
        ? `Valid: ${ok}`
        : "Does not match any format (or is too long).";
    } catch (err) {
      out.className = "small text-danger";
      out.textContent = err.message;
    }
  };

  drawFormats();

  mHost.addEventListener("input", (e) => {
    const row = e.target.closest("[data-i]");
    if (row && e.target.dataset.f)
      formats[Number(row.dataset.i)][e.target.dataset.f] = e.target.value;
    tryIt();
  });

  mHost.addEventListener("click", (e) => {
    const rm = e.target.closest("[data-rm]");

    if (rm) {
      formats.splice(Number(rm.dataset.rm), 1);
      drawFormats();
      tryIt();
    }

    if (e.target.closest("#hub-add-format")) {
      formats.push({ label: "", pattern: "", example: "" });
      drawFormats();
    }
  });

  mForm.elements.matricTry.addEventListener("input", tryIt);
  mForm.elements.matricMax.addEventListener("input", tryIt);

  mForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    for (const f of formats) {
      try {
        compileMatric(f.pattern);
      } catch (err) {
        return notify.error(
          "Check the pattern",
          `${f.label || "A format"}: ${err.message}`,
        );
      }
    }

    try {
      await saveSettings("matric", config());
      notify.toast("success", "Matric format saved");
    } catch (err) {
      fail(err);
    }
  });

  // ---- level progression
  const pHost = root.querySelector("#hub-progression");

  if (pHost) {
    const levelNames = (s.levels ?? []).map((l) => l.name);
    let map = { ...(s.progression?.map ?? {}) };
    let last = s.progression?.last ?? null;

    const drawProgression = (previewHtml = "") => {
      pHost.innerHTML = `
        <div class="table-responsive"><table class="table table-sm align-middle mb-2" style="max-width:34rem">
          <thead><tr><th>Level</th><th>Moves to</th></tr></thead>
          <tbody>${levelNames
            .map(
              (n) => `<tr><td>${esc(n)}</td><td>
            <select class="form-select form-select-sm" data-from="${esc(n)}" ${editable ? "" : "disabled"}>
              <option value="">Stays at ${esc(n)}</option>
              ${levelNames
                .filter((x) => x !== n)
                .map(
                  (x) =>
                    `<option value="${esc(x)}" ${map[n] === x ? "selected" : ""}>${esc(x)}</option>`,
                )
                .join("")}
            </select></td></tr>`,
            )
            .join("")}</tbody>
        </table></div>
        ${editable ? `<button type="button" class="btn btn-gold btn-sm" id="progSave">Save progression</button>` : ""}
        ${isSuper ? `<button type="button" class="btn btn-ghost btn-sm ms-2" id="progPreview">Preview</button>` : ""}
        <div id="progOut" class="mt-3">${previewHtml}</div>
        <p class="small text-secondary mt-2 mb-0">${last ? `Last moved: ${esc(String(last.at).slice(0, 10))}, ${last.moved} students${last.by ? ` (by ${esc(last.by)})` : ""}.` : "Students have not been moved up yet."}</p>`;
    };

    drawProgression();

    pHost.addEventListener("change", (e) => {
      const from = e.target.dataset?.from;
      if (!from) return;

      if (e.target.value) map[from] = e.target.value;
      else delete map[from];
    });

    pHost.addEventListener("click", async (e) => {
      try {
        if (e.target.closest("#progSave")) {
          await saveSettings("progression", { map });
          notify.toast("success", "Progression saved");
        } else if (e.target.closest("#progPreview")) {
          const r = await previewPromotion();
          const rows = Object.entries(r.counts ?? {});

          drawProgression(`<div class="oga-banner" role="note"><span>${rows.length ? `${r.total} students would move: ${rows.map(([lvl, n]) => `${n} from ${esc(lvl)} to ${esc(r.map[lvl])}`).join(", ")}.` : "No students are at the levels that move."}</span></div>
            ${
              r.total && isSuper
                ? `<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="progAgain"><label class="form-check-label small" for="progAgain">Apply again (only if you mean to move them a second time this session)</label></div>
            <button type="button" class="btn btn-danger btn-sm mt-2" id="progApply">Move ${r.total} students up now</button>`
                : ""
            }`);
        } else if (e.target.closest("#progApply")) {
          const again = Boolean(pHost.querySelector("#progAgain")?.checked);

          if (
            !(await notify.confirm({
              title: "Move all these students up a level?",
              text: "This changes the level kept on every student account. It cannot be undone automatically.",
              confirmText: "Move them up",
              danger: true,
            }))
          )
            return;

          const r = await applyPromotion({ again });
          last = {
            at: new Date().toISOString(),
            moved: r.moved,
            by: "you",
          };

          drawProgression(
            `<div class="oga-banner" role="note"><span>${r.moved} students moved up.</span></div>`,
          );
          notify.toast("success", `${r.moved} students moved up`);
        }
      } catch (err) {
        fail(err);
      }
    });
  }

  // ---- secrets
  const sForm = root.querySelector("#secretsForm");

  if (isSuper && sForm) {
    sForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = sForm.elements;

      const value = {
        clear: [...sForm.querySelectorAll("[data-clear]:checked")].map(
          (c) => c.dataset.clear,
        ),
      };

      for (const k of ["flutterwaveSecretKey", "flutterwaveWebhookHash"])
        if (f[k].value.trim() && !value.clear.includes(k))
          value[k] = f[k].value.trim();

      if (
        !value.clear.length &&
        !value.flutterwaveSecretKey &&
        !value.flutterwaveWebhookHash
      )
        return notify.toast("info", "Nothing to save");

      try {
        await saveSettings("secrets", value);
        notify.toast("success", "Secrets saved");
        setTimeout(() => location.reload(), 900);
      } catch (err) {
        fail(err);
      }
    });

    root.querySelector("#secretsTest")?.addEventListener("click", async () => {
      const out = root.querySelector("#hub-secret-test");
      out.className = "ms-2 small text-secondary";
      out.textContent = "Testing…";

      try {
        const r = await testGatewayKey();
        out.className = `ms-2 small ${r.ok ? "text-success" : "text-danger"}`;
        out.textContent = r.message;
      } catch (err) {
        out.className = "ms-2 small text-danger";
        out.textContent = err.message;
      }
    });
  }
}
