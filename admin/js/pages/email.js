import { html, esc } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { saveSettings } from "../services/settings.api.js";
import {
  loadEmail,
  saveTemplate,
  resetTemplate,
  previewTemplate,
  sendTestEmail,
  loadOutbox,
  sendQueued,
  retryEmail,
} from "../services/email.api.js";

export const title = "Email";
export const perm = "settings.view";
export const crumbs = ["Administration", "Email"];

export default async function email() {
  return html` <div id="emailStatus" class="mb-3"></div>
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item">
        <button
          class="nav-link active"
          data-bs-toggle="tab"
          data-bs-target="#tab-delivery"
          type="button"
          role="tab"
        >
          Delivery
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          data-bs-toggle="tab"
          data-bs-target="#tab-templates"
          type="button"
          role="tab"
        >
          Templates
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          id="outboxTabBtn"
          data-bs-toggle="tab"
          data-bs-target="#tab-outbox"
          type="button"
          role="tab"
        >
          Outbox
        </button>
      </li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tab-delivery"></div>
      <div class="tab-pane fade" id="tab-templates"></div>
      <div class="tab-pane fade" id="tab-outbox"></div>
    </div>`;
}

const field = (
  label,
  name,
  value,
  { type = "text", col = "col-md-6", help = "", ph = "" } = {},
) => `
  <div class="${col} mb-3">
    <label class="form-label" for="em-${name}">${label}</label>
    <input class="form-control" id="em-${name}" name="${name}" type="${type}" value="${esc(value ?? "")}" placeholder="${esc(ph)}" autocomplete="${type === "password" ? "new-password" : "off"}">
    ${help ? `<div class="form-text">${help}</div>` : ""}
  </div>`;

export async function init(root, { can }) {
  const editable = can("settings.edit");
  let data = await loadEmail();
  const fail = (e) => notify.failure(e);

  const statusBar = () => {
    const c = data.counts;
    root.querySelector("#emailStatus").innerHTML = `
      <div class="d-flex flex-wrap align-items-center gap-3">
        <span class="badge ${data.configured ? "text-bg-success" : "text-bg-danger"}">${data.configured ? "Ready to send" : "Email is not set up yet"}</span>
        <span class="small text-secondary">Waiting: <strong>${c.queued}</strong> · Sent: <strong>${c.sent}</strong> · Failed: <strong>${c.failed}</strong></span>
      </div>`;
  };
  statusBar();

  // ================================================================ Delivery
  const d = data.delivery;
  const deliveryHost = root.querySelector("#tab-delivery");
  deliveryHost.innerHTML = `
    <div class="card mb-4"><div class="card-header"><h2 class="card-title mb-0">Delivery and sender</h2></div><div class="card-body">
      <p class="text-secondary">Every email the site sends (codes, invitations, committee messages and everything added later) goes out through this one connection. The password is encrypted and never shown again.</p>
      <form id="deliveryForm" novalidate><fieldset ${editable ? "" : "disabled"}>
        <h3 class="h6">SMTP server</h3>
        <div class="row">
          ${field("Host", "smtpHost", d.smtpHost, { col: "col-md-5", ph: "mail.yourdomain.com" })}
          ${field("Port", "smtpPort", d.smtpPort || "587", { col: "col-md-2" })}
          <div class="col-md-5 mb-3"><label class="form-label" for="em-smtpEncryption">Encryption</label>
            <select class="form-select" id="em-smtpEncryption" name="smtpEncryption">
              ${[
                ["tls", "TLS / STARTTLS (port 587)"],
                ["ssl", "SSL (port 465)"],
                ["none", "None"],
              ]
                .map(
                  ([v, l]) =>
                    `<option value="${v}" ${d.smtpEncryption === v ? "selected" : ""}>${l}</option>`,
                )
                .join("")}
            </select></div>
          ${field("Username", "smtpUsername", d.smtpUsername, { ph: "noreply@yourdomain.com" })}
          ${field("Password", "smtpPassword", "", { type: "password", help: d.smtpPasswordConfigured ? "A password is saved. Leave blank to keep it." : "No password saved yet.", ph: d.smtpPasswordConfigured ? "••••••••" : "" })}
        </div>
        <h3 class="h6 mt-2">Sender</h3>
        <div class="row">
          ${field("Sender name", "fromName", d.fromName, { col: "col-md-4", help: "Shown as the sender. Empty = the site name." })}
          ${field("Sender email", "fromEmail", d.fromEmail, { col: "col-md-4", ph: "noreply@yourdomain.com", help: "Use an address on your own domain so mail is not marked as spam." })}
          ${field("Reply-to email", "replyTo", d.replyTo, { col: "col-md-4", help: "Optional: where replies go." })}
          <div class="col-12 mb-3"><label class="form-label" for="em-footerText">Footer line</label>
            <input class="form-control" id="em-footerText" name="footerText" maxlength="300" value="${esc(d.footerText ?? "")}" placeholder="If you did not expect this email, you can ignore it.">
            <div class="form-text">Shown under every email, after the site name.</div></div>
        </div>
        ${editable ? `<button class="btn btn-gold" type="submit">Save delivery settings</button>` : ""}
      </fieldset></form>
    </div></div>
    <div class="card mb-4"><div class="card-header"><h2 class="card-title mb-0">Send a test email</h2></div><div class="card-body">
      <p class="text-secondary">Save the settings above first, then send yourself a test to confirm it works.</p>
      <div class="input-group" style="max-width:34rem">
        <input class="form-control" id="testTo" type="email" placeholder="you@example.com" ${editable ? "" : "disabled"}>
        <button class="btn btn-gold" id="testBtn" type="button" ${editable ? "" : "disabled"}>Send test</button>
      </div>
      <div id="testResult" class="small mt-2"></div>
    </div></div>`;

  const showResult = (el, r) => {
    el.className = `small mt-2 ${r.ok ? "text-success" : "text-danger"}`;
    el.textContent = r.message;
  };
  deliveryHost
    .querySelector("#deliveryForm")
    .addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target.elements;
      try {
        await saveSettings("communications", {
          emailMode: "smtp",
          smtpHost: f.smtpHost.value.trim(),
          smtpPort: f.smtpPort.value.trim(),
          smtpEncryption: f.smtpEncryption.value,
          smtpUsername: f.smtpUsername.value.trim(),
          smtpPassword: f.smtpPassword.value,
          fromName: f.fromName.value.trim(),
          fromEmail: f.fromEmail.value.trim(),
          replyTo: f.replyTo.value.trim(),
          footerText: f.footerText.value.trim(),
        });
        f.smtpPassword.value = "";
        data = await loadEmail();
        statusBar();
        notify.toast("success", "Delivery settings saved");
      } catch (err) {
        fail(err);
      }
    });
  deliveryHost.querySelector("#testBtn").addEventListener("click", async () => {
    const out = deliveryHost.querySelector("#testResult");
    out.className = "small mt-2 text-secondary";
    out.textContent = "Sending…";
    try {
      showResult(
        out,
        await sendTestEmail(deliveryHost.querySelector("#testTo").value.trim()),
      );
    } catch (err) {
      out.className = "small mt-2 text-danger";
      out.textContent = err.message;
    }
  });

  // ================================================================ Templates
  const tplHost = root.querySelector("#tab-templates");
  let current = data.templates[0].key;
  let focused = "body";
  const find = (k) => data.templates.find((t) => t.key === k);

  const drawTemplates = () => {
    const groups = [...new Set(data.templates.map((t) => t.group))];
    const t = find(current);
    tplHost.innerHTML = `
      <div class="row g-3">
        <div class="col-lg-3"><div class="list-group">
          ${groups
            .map(
              (g) =>
                `<div class="list-group-item bg-transparent small text-secondary text-uppercase">${esc(g)}</div>` +
                data.templates
                  .filter((x) => x.group === g)
                  .map(
                    (x) => `
            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ${x.key === current ? "active" : ""}" data-pick="${x.key}">
              <span>${esc(x.label)}</span>${x.custom ? `<span class="badge text-bg-warning">Edited</span>` : ""}
            </button>`,
                  )
                  .join(""),
            )
            .join("")}
        </div></div>
        <div class="col-lg-9"><div class="card"><div class="card-body">
          <h2 class="h5 mb-1">${esc(t.label)}</h2>
          <p class="text-secondary small">${esc(t.description)}</p>
          <label class="form-label" for="tplSubject">Subject</label>
          <input class="form-control mb-3" id="tplSubject" value="${esc(t.subject)}" maxlength="200" ${editable ? "" : "disabled"}>
          <label class="form-label" for="tplBody">Message</label>
          <textarea class="form-control mb-2" id="tplBody" rows="13" style="font-family:monospace" ${editable ? "" : "disabled"}>${esc(t.body)}</textarea>
          <div class="small text-secondary mb-2">Click to insert:
            ${Object.entries(t.placeholders)
              .map(
                ([k, v]) =>
                  `<button type="button" class="btn btn-sm btn-ghost me-1 mb-1" data-ph="${k}" title="${esc(v)}">{{${k}}}</button>`,
              )
              .join("")}
          </div>
          <details class="small text-secondary mb-3"><summary>How the message is built</summary>
            <ul class="mt-2 mb-0">
              <li>A blank line starts a new paragraph.</li>
              <li>A line whose placeholders are all empty is left out (so “Date: {{date}}” disappears when there is no date).</li>
              <li><code>[[Button text | {{link}}]]</code> on its own makes a button.</li>
              <li>A code placeholder on its own is shown as a highlighted code block.</li>
              <li>Hover a placeholder to see what it holds.</li>
            </ul></details>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            ${editable ? `<button class="btn btn-gold" id="tplSave" type="button">Save</button>` : ""}
            <button class="btn btn-ghost" id="tplPreview" type="button">Preview</button>
            ${editable && t.custom ? `<button class="btn btn-ghost" id="tplReset" type="button">Reset to default</button>` : ""}
          </div>
          ${editable ? `<div class="input-group mt-3" style="max-width:34rem"><input class="form-control" id="tplTestTo" type="email" placeholder="Send this test to…"><button class="btn btn-ghost" id="tplTest" type="button">Send test</button></div><div id="tplTestResult" class="small mt-2"></div>` : ""}
          <div id="tplPreviewBox" class="mt-3" hidden>
            <div class="small text-secondary mb-1">Preview with sample values · Subject: <strong id="tplPreviewSubject"></strong></div>
            <iframe id="tplFrame" sandbox="" style="width:100%;height:420px;border:1px solid var(--bs-border-color);border-radius:6px;background:#fff" title="Email preview"></iframe>
          </div>
        </div></div></div>
      </div>`;
  };
  drawTemplates();

  const edits = () => ({
    subject: tplHost.querySelector("#tplSubject").value.trim(),
    body: tplHost.querySelector("#tplBody").value,
  });
  const dirty = () => {
    const t = find(current);
    const e = edits();
    return e.subject !== t.subject || e.body !== t.body;
  };

  tplHost.addEventListener("focusin", (e) => {
    if (e.target.id === "tplSubject") focused = "subject";
    if (e.target.id === "tplBody") focused = "body";
  });
  tplHost.addEventListener("click", async (e) => {
    const pick = e.target.closest("[data-pick]");
    const ph = e.target.closest("[data-ph]");
    try {
      if (pick && pick.dataset.pick !== current) {
        if (
          editable &&
          dirty() &&
          !(await notify.confirm({
            title: "Discard your changes?",
            text: "You have unsaved changes to this template.",
            confirmText: "Discard",
          }))
        )
          return;
        current = pick.dataset.pick;
        drawTemplates();
      } else if (ph) {
        const el = tplHost.querySelector(
          focused === "subject" ? "#tplSubject" : "#tplBody",
        );
        const s = el.selectionStart ?? el.value.length;
        const token = `{{${ph.dataset.ph}}}`;
        el.value =
          el.value.slice(0, s) + token + el.value.slice(el.selectionEnd ?? s);
        el.focus();
        el.selectionStart = el.selectionEnd = s + token.length;
      } else if (e.target.closest("#tplSave")) {
        const r = await saveTemplate(current, edits().subject, edits().body);
        data.templates = r.templates;
        drawTemplates();
        notify.toast("success", "Template saved");
      } else if (e.target.closest("#tplReset")) {
        if (
          !(await notify.confirm({
            title: "Back to the default wording?",
            text: "Your version of this email will be removed.",
            confirmText: "Reset",
          }))
        )
          return;
        data.templates = (await resetTemplate(current)).templates;
        drawTemplates();
        notify.toast("success", "Default wording restored");
      } else if (e.target.closest("#tplPreview")) {
        const r = await previewTemplate(current, edits().subject, edits().body);
        tplHost.querySelector("#tplPreviewSubject").textContent = r.subject;
        tplHost.querySelector("#tplFrame").srcdoc = r.html;
        tplHost.querySelector("#tplPreviewBox").hidden = false;
      } else if (e.target.closest("#tplTest")) {
        const out = tplHost.querySelector("#tplTestResult");
        out.className = "small mt-2 text-secondary";
        out.textContent = "Sending…";
        showResult(
          out,
          await sendTestEmail(
            tplHost.querySelector("#tplTestTo").value.trim(),
            current,
            edits().subject,
            edits().body,
          ),
        );
      }
    } catch (err) {
      const out = tplHost.querySelector("#tplTestResult");
      if (e.target.closest("#tplTest") && out) {
        out.className = "small mt-2 text-danger";
        out.textContent = err.message;
      } else fail(err);
    }
  });

  // ================================================================ Outbox (loads when the tab is first opened)
  const outHost = root.querySelector("#tab-outbox");
  const filters = { status: "", type: "", q: "", page: 1 };
  let outboxReady = false;
  const badge = (s) =>
    `<span class="badge ${s === "sent" ? "text-bg-success" : s === "failed" ? "text-bg-danger" : "text-bg-warning"}">${s}</span>`;

  const drawOutbox = async () => {
    let r;
    try {
      r = await loadOutbox(filters);
    } catch (err) {
      return fail(err);
    }
    filters.page = r.page;
    outHost.innerHTML = `
      <div class="card"><div class="card-body">
        <div class="row g-2 align-items-end mb-3">
          <div class="col-md-2"><label class="form-label small" for="obStatus">Status</label>
            <select class="form-select" id="obStatus">${[
              ["", "All"],
              ["queued", "Waiting"],
              ["sent", "Sent"],
              ["failed", "Failed"],
            ]
              .map(
                ([v, l]) =>
                  `<option value="${v}" ${filters.status === v ? "selected" : ""}>${l}</option>`,
              )
              .join("")}</select></div>
          <div class="col-md-3"><label class="form-label small" for="obType">Type</label>
            <select class="form-select" id="obType"><option value="">All types</option>${data.templates.map((t) => `<option value="${t.key}" ${filters.type === t.key ? "selected" : ""}>${esc(t.label)}</option>`).join("")}</select></div>
          <div class="col-md-4"><label class="form-label small" for="obQ">Recipient</label>
            <input class="form-control" id="obQ" value="${esc(filters.q)}" placeholder="Part of an email address"></div>
          <div class="col-md-3 d-flex gap-2"><button class="btn btn-gold" id="obSearch" type="button">Search</button>
            ${editable ? `<button class="btn btn-ghost" id="obSend" type="button" title="Send everything that is waiting, now">Send waiting now</button>` : ""}</div>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-2">
          <thead><tr><th>When</th><th>Email</th><th>To</th><th>Status</th><th>Tries</th><th>Why it failed</th><th></th></tr></thead>
          <tbody>${
            r.rows.length
              ? r.rows
                  .map(
                    (m) => `<tr>
            <td class="text-nowrap small">${esc((m.sentAt ?? m.createdAt ?? "").slice(0, 16))}</td>
            <td>${esc(m.label)}</td><td>${esc(m.recipient)}</td><td>${badge(m.status)}</td><td>${m.attempts}</td>
            <td class="small text-secondary">${esc(m.error ?? "")}</td>
            <td class="text-end">${editable && m.status === "failed" ? `<button class="btn btn-sm btn-ghost" data-retry="${m.id}" ${m.retryable ? "" : "disabled"} title="${m.retryable ? "Try sending again" : "The one-time code was deleted: ask the person to request a new one"}">Retry</button>` : ""}</td>
          </tr>`,
                  )
                  .join("")
              : `<tr><td colspan="7" class="text-center text-secondary py-4">No emails match.</td></tr>`
          }</tbody>
        </table></div>
        <div class="d-flex justify-content-between align-items-center">
          <span class="small text-secondary">${r.total} message${r.total === 1 ? "" : "s"} · page ${r.page} of ${r.pages}</span>
          <div class="btn-group"><button class="btn btn-sm btn-ghost" id="obPrev" ${r.page <= 1 ? "disabled" : ""}>Previous</button><button class="btn btn-sm btn-ghost" id="obNext" ${r.page >= r.pages ? "disabled" : ""}>Next</button></div>
        </div>
      </div></div>`;
  };
  const refreshAll = async () => {
    data = { ...data, ...(await loadEmail()) };
    statusBar();
    await drawOutbox();
  };
  const readFilters = () => {
    filters.status = outHost.querySelector("#obStatus").value;
    filters.type = outHost.querySelector("#obType").value;
    filters.q = outHost.querySelector("#obQ").value.trim();
  };

  root
    .querySelector("#outboxTabBtn")
    .addEventListener("shown.bs.tab", async () => {
      if (!outboxReady) {
        outboxReady = true;
        await drawOutbox();
      }
    });
  // No request on every keystroke: the Search button (or Enter) runs the search once.
  outHost.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && e.target.id === "obQ") {
      e.preventDefault();
      outHost.querySelector("#obSearch").click();
    }
  });
  outHost.addEventListener("click", async (e) => {
    try {
      if (e.target.closest("#obSearch")) {
        readFilters();
        filters.page = 1;
        await drawOutbox();
      } else if (e.target.closest("#obPrev")) {
        filters.page--;
        await drawOutbox();
      } else if (e.target.closest("#obNext")) {
        filters.page++;
        await drawOutbox();
      } else if (e.target.closest("#obSend")) {
        const r = await sendQueued();
        if (!r.ok) return notify.toast("error", r.message);
        notify.toast(
          "success",
          `Sent ${r.result.sent}, failed ${r.result.failed}`,
        );
        await refreshAll();
      } else if (e.target.closest("[data-retry]")) {
        const r = await retryEmail(
          e.target.closest("[data-retry]").dataset.retry,
        );
        notify.toast(r.ok ? "success" : "error", r.message);
        await refreshAll();
      }
    } catch (err) {
      fail(err);
    }
  });
}
