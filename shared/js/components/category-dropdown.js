/**
 * A dropdown for choosing categories that stays tidy with 30+ of them: a button that opens a panel with a search box and a
 * scrolling list. One choice (registration) or several (adding categories in the portal, up to a limit).
 *
 * The list you give it is already filtered by the server to what this person may enter; searching here only narrows that
 * list on the page (no request is made while typing). The server checks every choice again.
 *
 *   const dd = mountCategoryDropdown(host, { categories, multi: false, onChange: (ids) => … });
 *   dd.values()  -> ["12"]      dd.set(list)  -> replace the options      dd.clear()
 */
let counter = 0;

const el = (tag, attrs = {}, ...kids) => {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === false || v === null || v === undefined) continue;
    if (k === "class") n.className = v;
    else if (k === "text") n.textContent = v;
    else n.setAttribute(k, v === true ? "" : String(v));
  }
  n.append(...kids);
  return n;
};

export function mountCategoryDropdown(
  host,
  {
    categories = [],
    multi = false,
    max = 1,
    placeholder = multi ? "Choose categories" : "Choose a category",
    searchLabel = "Search categories",
    emptyText = "No categories are open to you.",
    onChange = () => {},
  } = {},
) {
  const uid = `cdd${++counter}`;
  let list = [];
  let picked = []; // ids, in the order chosen
  let limit = Math.max(1, Number(max) || 1);
  let disabled = false;

  const button = el("button", {
    type: "button",
    class: "oga-cdd__btn form-select text-start",
    id: `${uid}-btn`,
    "aria-haspopup": "listbox",
    "aria-expanded": "false",
    "aria-controls": `${uid}-panel`,
  });
  const search = el("input", {
    type: "search",
    class: "form-control form-control-sm oga-cdd__search",
    placeholder: searchLabel,
    "aria-label": searchLabel,
    autocomplete: "off",
  });
  const note = el("div", {
    class: "oga-cdd__note small text-secondary",
    "aria-live": "polite",
  });
  const ul = el("ul", {
    class: "oga-cdd__list",
    role: "listbox",
    id: `${uid}-list`,
    "aria-multiselectable": multi ? "true" : "false",
    "aria-labelledby": `${uid}-btn`,
  });
  const panel = el(
    "div",
    { class: "oga-cdd__panel", id: `${uid}-panel`, hidden: true },
    search,
    note,
    ul,
  );
  const chips = el("div", { class: "oga-cdd__chips" });
  const root = el("div", { class: "oga-cdd" }, button, panel, chips);
  host.replaceChildren(root);

  const byId = (id) => list.find((c) => c.id === id);
  const names = () => picked.map((id) => byId(id)?.name).filter(Boolean);

  const paintButton = () => {
    button.disabled = disabled;
    if (!picked.length) {
      button.textContent = list.length ? placeholder : emptyText;
      button.classList.add("oga-cdd__btn--empty");
    } else {
      button.textContent = multi
        ? `${picked.length} of ${limit} chosen`
        : names()[0];
      button.classList.remove("oga-cdd__btn--empty");
    }
    chips.replaceChildren(
      ...(multi
        ? picked.map((id) => {
            const x = el(
              "button",
              {
                type: "button",
                class: "oga-cdd__x",
                "aria-label": `Remove ${byId(id)?.name ?? "category"}`,
                "data-id": id,
              },
              el("i", { class: "fa-solid fa-xmark", "aria-hidden": "true" }),
            );
            return el(
              "span",
              { class: "oga-cdd__chip" },
              byId(id)?.name ?? "",
              x,
            );
          })
        : []),
    );
  };

  const options = () => [
    ...ul.querySelectorAll('[role="option"]:not([hidden])'),
  ];

  const paintList = () => {
    const q = search.value.trim().toLowerCase();
    ul.replaceChildren(
      ...list.map((c) => {
        const on = picked.includes(c.id);
        const full = multi && !on && picked.length >= limit;
        const matches =
          !q || `${c.name} ${c.description ?? ""}`.toLowerCase().includes(q);
        return el(
          "li",
          {
            role: "option",
            class: `oga-cdd__opt${on ? " is-on" : ""}${full ? " is-full" : ""}`,
            "data-id": c.id,
            "aria-selected": on ? "true" : "false",
            "aria-disabled": full ? "true" : false,
            tabindex: "-1",
            hidden: !matches,
          },
          el(
            "span",
            { class: "oga-cdd__tick", "aria-hidden": "true" },
            on ? el("i", { class: "fa-solid fa-check" }) : "",
          ),
          el(
            "span",
            { class: "oga-cdd__label" },
            el("strong", { text: c.name }),
            c.description
              ? el("small", {
                  class: "d-block text-secondary",
                  text: c.description,
                })
              : "",
          ),
        );
      }),
    );
    const shown = options().length;
    note.textContent = !list.length
      ? emptyText
      : q && !shown
        ? "No category matches your search."
        : multi
          ? `${picked.length} of ${limit} chosen. ${list.length} open to you.`
          : `${list.length} open to you.`;
  };

  const open = () => {
    if (disabled || panel.hidden === false) return;
    panel.hidden = false;
    button.setAttribute("aria-expanded", "true");
    search.value = "";
    paintList();
    (list.length > 8 ? search : (options()[0] ?? search)).focus();
  };
  const close = (refocus = false) => {
    if (panel.hidden) return;
    panel.hidden = true;
    button.setAttribute("aria-expanded", "false");
    if (refocus) button.focus();
  };

  const choose = (id) => {
    if (multi) {
      if (picked.includes(id)) picked = picked.filter((x) => x !== id);
      else if (picked.length < limit) picked = [...picked, id];
      else return;
    } else {
      picked = [id];
    }
    paintButton();
    paintList();
    onChange(picked.slice());
    if (!multi) close(true);
  };

  button.addEventListener("click", () => (panel.hidden ? open() : close()));
  button.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      open();
    }
  });
  search.addEventListener("input", paintList);
  search.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      options()[0]?.focus();
    } else if (e.key === "Escape") close(true);
    else if (e.key === "Enter") {
      e.preventDefault();
      const only = options();
      if (only.length === 1) choose(only[0].dataset.id);
    }
  });
  ul.addEventListener("click", (e) => {
    const li = e.target.closest('[role="option"]');
    if (li && li.getAttribute("aria-disabled") !== "true")
      choose(li.dataset.id);
  });
  ul.addEventListener("keydown", (e) => {
    const all = options();
    const i = all.indexOf(document.activeElement);
    if (e.key === "ArrowDown") {
      e.preventDefault();
      all[Math.min(all.length - 1, i + 1)]?.focus();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      i <= 0 ? search.focus() : all[i - 1].focus();
    } else if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      if (i >= 0 && all[i].getAttribute("aria-disabled") !== "true")
        choose(all[i].dataset.id);
    } else if (e.key === "Escape") close(true);
    else if (e.key === "Home") {
      e.preventDefault();
      all[0]?.focus();
    } else if (e.key === "End") {
      e.preventDefault();
      all.at(-1)?.focus();
    }
  });
  chips.addEventListener("click", (e) => {
    const x = e.target.closest("[data-id]");
    if (x) choose(x.dataset.id);
  });
  document.addEventListener("click", (e) => {
    if (!root.isConnected) return;
    if (!root.contains(e.target)) close();
  });
  root.addEventListener("focusout", (e) => {
    if (e.relatedTarget && !root.contains(e.relatedTarget)) close();
  });

  const api = {
    values: () => picked.slice(),
    names,
    /** Replace the options. A choice that is no longer in the list is dropped. */
    set(next) {
      list = (next ?? []).map((c) => ({ ...c, id: String(c.id) }));
      const before = picked.join();
      picked = picked.filter((id) => list.some((c) => c.id === id));
      paintButton();
      paintList();
      if (picked.join() !== before) onChange(picked.slice());
    },
    setMax(n) {
      limit = Math.max(1, Number(n) || 1);
      if (picked.length > limit) picked = picked.slice(0, limit);
      paintButton();
      paintList();
    },
    clear() {
      picked = [];
      paintButton();
      paintList();
      onChange([]);
    },
    disable(on = true) {
      disabled = on;
      if (on) close();
      paintButton();
    },
    focus: () => button.focus(),
  };
  api.set(categories);
  return api;
}
