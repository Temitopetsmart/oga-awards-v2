import { html, toHTML } from "../../../shared/js/core/dom.js";
import { mediaUrl } from "../../../shared/js/core/media.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  listPages,
  readPage,
  savePage,
  resetPage,
  uploadGalleryImage,
} from "../services/sitecontent.api.js";

export const title = "Site content";
export const perm = "content.view";
export const crumbs = ["Content", "Site content"];

const HOME_STATES = [
  ["landing", "Before nominations"],
  ["nominations", "Nominations open"],
  ["voting", "Voting open"],
  ["voting-closed", "Voting closed"],
  ["results", "Winners announced"],
];
const HOME_FIELDS = [
  ["kicker", "Small heading", 80],
  ["title", "Title", 120],
  ["highlight", "Highlighted words", 80],
  ["lead", "Introduction", 400],
  ["sectionTitle", "Section heading", 100],
  ["sectionLead", "Section introduction", 300],
];

const getIn = (o, p) => p.split(".").reduce((a, k) => a?.[k], o);
const setIn = (o, p, v) => {
  const ks = p.split(".");
  const last = ks.pop();
  ks.reduce((a, k) => a[k], o)[last] = v;
};

/** A text box or text area tied to `path` in the state. */
const box = (path, value, label, { rows = 0, help = "", max = 0 } = {}) =>
  html`<div class="mb-2">
    <label class="form-label small mb-1">${label}</label>
    ${rows
      ? html`<textarea
          class="form-control form-control-sm"
          rows="${rows}"
          data-f="${path}"
          ${max ? `maxlength=${max}` : ""}
        >
${value ?? ""}</textarea
        >`
      : html`<input
          class="form-control form-control-sm"
          data-f="${path}"
          value="${value ?? ""}"
          ${max ? `maxlength=${max}` : ""}
        />`}
    ${help ? html`<div class="form-text">${help}</div>` : ""}
  </div>`;

const rowCard = (children, removeKey) =>
  html`<div class="border rounded p-2 mb-2">
    ${children}
    <button
      class="btn btn-ghost btn-sm"
      type="button"
      data-remove="${removeKey}"
    >
      <i class="fa-solid fa-trash me-1"></i>Remove
    </button>
  </div>`;

/** One editor per page. Each returns the form and how to turn its state into what the server wants. */
const EDITORS = {
  faqs: {
    fresh: (c) => ({ items: (c ?? []).map((x) => ({ ...x })) }),
    form: (s) =>
      html` <p class="small text-secondary">
          Questions shown on the FAQ page, grouped by the group name.
        </p>
        ${s.items.map((it, i) =>
          rowCard(
            html`${box(`items.${i}.group`, it.group, "Group", { max: 40 })}
            ${box(`items.${i}.q`, it.q, "Question", { max: 200 })}
            ${box(`items.${i}.a`, it.a, "Answer", { rows: 3, max: 2000 })}`,
            `items.${i}`,
          ),
        )}
        <button class="btn btn-ghost btn-sm" type="button" data-add="items">
          <i class="fa-solid fa-plus me-1"></i>Add a question
        </button>`,
    blank: { items: { group: "General", q: "", a: "" } },
    payload: (s) => ({ items: s.items }),
  },
  about: {
    fresh: (c) => ({
      kicker: c?.kicker ?? "",
      title: c?.title ?? "",
      lead: c?.lead ?? "",
      story: [...(c?.story ?? [])],
      valuesTitle: c?.valuesTitle ?? "",
      values: (c?.values ?? []).map((v) => ({ ...v })),
    }),
    form: (s) =>
      html` ${box("kicker", s.kicker, "Small heading", { max: 60 })}
        ${box("title", s.title, "Title", { max: 150 })}
        ${box("lead", s.lead, "Introduction", { rows: 2, max: 400 })}
        <h3 class="h6 mt-3">Our story</h3>
        ${s.story.map((p, i) =>
          rowCard(
            box(`story.${i}`, p, `Paragraph ${i + 1}`, { rows: 3, max: 1200 }),
            `story.${i}`,
          ),
        )}
        <button
          class="btn btn-ghost btn-sm mb-3"
          type="button"
          data-add="story"
        >
          <i class="fa-solid fa-plus me-1"></i>Add a paragraph
        </button>
        ${box("valuesTitle", s.valuesTitle, "Values heading", { max: 80 })}
        <h3 class="h6 mt-3">Values</h3>
        ${s.values.map((v, i) =>
          rowCard(
            html`${box(`values.${i}.icon`, v.icon, "Icon", {
              help: "A Font Awesome name, for example fa-star.",
              max: 40,
            })}
            ${box(`values.${i}.title`, v.title, "Title", { max: 60 })}
            ${box(`values.${i}.text`, v.text, "Text", { max: 200 })}`,
            `values.${i}`,
          ),
        )}
        <button class="btn btn-ghost btn-sm" type="button" data-add="values">
          <i class="fa-solid fa-plus me-1"></i>Add a value
        </button>`,
    blank: { story: "", values: { icon: "fa-star", title: "", text: "" } },
    payload: (s) => ({ ...s }),
  },
  legal: {
    fresh: (c) => ({
      which: "privacy",
      privacy: structuredClone(
        c?.privacy ?? { title: "", updated: "", draft: false, sections: [] },
      ),
      terms: structuredClone(
        c?.terms ?? { title: "", updated: "", draft: false, sections: [] },
      ),
    }),
    form: (s) => {
      const p = s.which;
      const page = s[p];
      return html` <div class="mb-3">
          <label class="form-label small mb-1">Page</label>
          <select class="form-select form-select-sm w-auto" data-which>
            <option value="privacy" ${p === "privacy" ? "selected" : ""}>
              Privacy policy
            </option>
            <option value="terms" ${p === "terms" ? "selected" : ""}>
              Terms of use
            </option>
          </select>
        </div>
        ${box(`${p}.title`, page.title, "Title", { max: 100 })}
        ${box(`${p}.updated`, page.updated, "Last updated", {
          help: "Date as 2026-10-17.",
          max: 10,
        })}
        <div class="form-check mb-3">
          <input
            class="form-check-input"
            type="checkbox"
            id="draftBox"
            data-check="${p}.draft"
            ${page.draft ? "checked" : ""}
          />
          <label class="form-check-label" for="draftBox"
            >Show a “draft” notice on this page</label
          >
        </div>
        ${page.sections.map((sec, i) =>
          rowCard(
            html`${box(`${p}.sections.${i}.heading`, sec.heading, "Heading", {
              max: 120,
            })}
            ${box(`${p}.sections.${i}.body`, sec.body, "Text", {
              rows: 4,
              max: 4000,
            })}`,
            `${p}.sections.${i}`,
          ),
        )}
        <button
          class="btn btn-ghost btn-sm"
          type="button"
          data-add="${p}.sections"
        >
          <i class="fa-solid fa-plus me-1"></i>Add a section
        </button>`;
    },
    blank: {
      "privacy.sections": { heading: "", body: "" },
      "terms.sections": { heading: "", body: "" },
    },
    payload: (s) => ({ privacy: s.privacy, terms: s.terms }),
  },
  gallery: {
    fresh: (c) => ({ items: (c ?? []).map((x) => ({ ...x })) }),
    form: (s) =>
      html` <p class="small text-secondary">
          Pictures from past editions. Upload a picture for each item (it is
          resized and checked on the server).
        </p>
        ${s.items.map((g, i) =>
          rowCard(
            html`<div class="row g-2">
              <div class="col-md-4">
                ${g.src
                  ? html`<img
                      src="${mediaUrl(g.src)}"
                      alt=""
                      class="img-fluid rounded mb-2"
                      style="max-height:120px"
                    />`
                  : html`<div class="text-secondary small mb-2">
                      No picture yet.
                    </div>`}
                <input
                  type="file"
                  class="form-control form-control-sm"
                  accept="image/*"
                  data-upload="${i}"
                />
              </div>
              <div class="col-md-8">
                ${box(`items.${i}.title`, g.title, "Title", { max: 80 })}
                ${box(`items.${i}.caption`, g.caption, "Caption", { max: 200 })}
                ${box(`items.${i}.edition`, g.edition, "Edition", {
                  help: "For example OGA 9.0. Items are grouped by this.",
                  max: 60,
                })}
              </div>
            </div>`,
            `items.${i}`,
          ),
        )}
        <button class="btn btn-ghost btn-sm" type="button" data-add="items">
          <i class="fa-solid fa-plus me-1"></i>Add a picture
        </button>`,
    blank: {
      items: { title: "", caption: "", edition: "", icon: "", src: "" },
    },
    payload: (s) => ({ items: s.items }),
  },
  home: {
    fresh: (c) => ({ which: "voting", states: structuredClone(c ?? {}) }),
    form: (s) =>
      html` <p class="small text-secondary">
          The words at the top of the home page for each stage of the awards.
          Buttons and the highlight cards are not changed here.
        </p>
        <div class="mb-3">
          <label class="form-label small mb-1">Stage</label>
          <select class="form-select form-select-sm w-auto" data-which>
            ${HOME_STATES.map(
              ([k, label]) =>
                html`<option value="${k}" ${s.which === k ? "selected" : ""}>
                  ${label}
                </option>`,
            )}
          </select>
        </div>
        ${HOME_FIELDS.map(([k, label, max]) =>
          box(`states.${s.which}.${k}`, s.states[s.which]?.[k] ?? "", label, {
            max,
            rows: k === "lead" || k === "sectionLead" ? 2 : 0,
          }),
        )}`,
    blank: {},
    payload: (s) => ({
      states: Object.fromEntries(
        Object.entries(s.states).map(([st, v]) => [
          st,
          Object.fromEntries(HOME_FIELDS.map(([k]) => [k, v?.[k] ?? ""])),
        ]),
      ),
    }),
  },
};

export default async function sitecontent({ edition, can }) {
  const pages = await listPages(edition.id);
  return html` <div class="oga-banner" role="note">
      <span
        ><i class="fa-solid fa-circle-info me-2"></i>Edit the wording of the
        public pages here. Until you save a page, the original text that came
        with the site is shown. “Reset” brings the original back.</span
      >
    </div>
    <div class="card">
      <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="contentTabs">
          ${pages.map(
            (p, i) =>
              html`<li class="nav-item">
                <button
                  class="nav-link ${i === 0 ? "active" : ""}"
                  type="button"
                  data-tab="${p.name}"
                >
                  ${p.label}${p.customised
                    ? html` <span class="oga-status oga-status--info"
                        >edited</span
                      >`
                    : ""}
                </button>
              </li>`,
          )}
        </ul>
      </div>
      <div class="card-body" id="contentEditor"></div>
    </div>`;
}

export async function init(root, { edition, can }) {
  const holder = root.querySelector("#contentEditor");
  const editable = can("content.edit");
  let name = "faqs";
  let state = null;

  const wire = () => {
    holder
      .querySelectorAll("[data-f]")
      .forEach((el) =>
        el.addEventListener("input", () =>
          setIn(state, el.dataset.f, el.value),
        ),
      );
    holder
      .querySelectorAll("[data-check]")
      .forEach((el) =>
        el.addEventListener("change", () =>
          setIn(state, el.dataset.check, el.checked),
        ),
      );
    holder.querySelector("[data-which]")?.addEventListener("change", (e) => {
      state.which = e.target.value;
      draw();
    });
    holder.querySelectorAll("[data-add]").forEach((b) =>
      b.addEventListener("click", () => {
        const path = b.dataset.add;
        const blank =
          EDITORS[name].blank[path] ??
          EDITORS[name].blank[path.split(".").slice(-2).join(".")] ??
          "";
        const target = getIn(state, path);
        target.push(typeof blank === "object" ? { ...blank } : blank);
        draw();
      }),
    );
    holder.querySelectorAll("[data-remove]").forEach((b) =>
      b.addEventListener("click", () => {
        const ks = b.dataset.remove.split(".");
        const idx = Number(ks.pop());
        getIn(state, ks.join(".")).splice(idx, 1);
        draw();
      }),
    );
    holder.querySelectorAll("[data-upload]").forEach((el) =>
      el.addEventListener("change", async () => {
        const file = el.files?.[0];
        if (!file) return;
        try {
          state.items[Number(el.dataset.upload)].src = await uploadGalleryImage(
            edition.id,
            file,
          );
          draw();
        } catch (err) {
          await notify.warning("Couldn’t upload it", err.message);
        }
      }),
    );
    holder.querySelector("#saveContent")?.addEventListener("click", save);
    holder.querySelector("#resetContent")?.addEventListener("click", reset);
  };

  const draw = () => {
    holder.innerHTML = toHTML(
      html`${EDITORS[name].form(state)}
      ${editable
        ? html`<div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-gold btn-sm" type="button" id="saveContent">
              Save
            </button>
            <button
              class="btn btn-ghost btn-sm"
              type="button"
              id="resetContent"
            >
              Reset to original
            </button>
          </div>`
        : ""}`,
    );
    if (!editable)
      holder
        .querySelectorAll(
          "input,textarea,select,button[data-add],button[data-remove]",
        )
        .forEach((e) => (e.disabled = true));
    wire();
  };

  const load = async (next) => {
    name = next;
    state = EDITORS[name].fresh(await readPage(edition.id, name));
    root
      .querySelectorAll("[data-tab]")
      .forEach((b) => b.classList.toggle("active", b.dataset.tab === name));
    draw();
  };

  const save = async () => {
    try {
      await savePage(edition.id, name, EDITORS[name].payload(state));
      notify.toast("success", "Saved. The public page now shows it.");
      await load(name);
    } catch (err) {
      await notify.warning("Couldn’t save", err.message);
    }
  };
  const reset = async () => {
    if (
      !(await notify.confirm({
        title: "Reset this page to the original text?",
        text: "Your edits to this page are removed.",
        confirmText: "Reset",
        danger: true,
      }))
    )
      return;
    await resetPage(edition.id, name);
    notify.toast("info", "Reset");
    await load(name);
  };

  root
    .querySelectorAll("[data-tab]")
    .forEach((b) => b.addEventListener("click", () => load(b.dataset.tab)));
  await load(name);
}
