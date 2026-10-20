import { html, mount, esc } from "../../../shared/js/core/dom.js";

/**
 * Reusable client-side data table: search, filters, sorting, pagination, row actions, bulk actions.
 *
 * new DataTable(container, {
 *   columns: [{ key, label, sort?: true, value?: row => any, render?: row => html, class? }],
 *   rows, searchKeys: ['name'], filters: [{ key, label, options: [{value,label}] }],
 *   actions: [{ key, label, icon, variant?, when?: row => bool }],
 *   bulkActions: [{ key, label, icon }], onAction(key, row), onBulk(key, rows),
 *   pageSize: 10, empty: 'Nothing here yet.'
 * })
 */
export class DataTable {
  constructor(root, opts) {
    this.root = root;
    this.o = {
      pageSize: 10,
      searchKeys: [],
      filters: [],
      actions: [],
      bulkActions: [],
      empty: "Nothing here yet.",
      can: () => true,
      ...opts,
    };
    // Actions and bulk actions carry a `perm`; the ones the person isn't allowed are dropped here.
    this.o.actions = this.o.actions.filter(
      (a) => !a.perm || this.o.can(a.perm),
    );
    this.o.bulkActions = this.o.bulkActions.filter(
      (a) => !a.perm || this.o.can(a.perm),
    );
    this.state = {
      q: "",
      sort: null,
      page: 1,
      filters: {},
      selected: new Set(),
    };
    this.rows = opts.rows ?? [];
    this.#shell();
    this.update();
  }

  setRows(rows) {
    this.rows = rows;
    this.state.selected.clear();
    this.update();
  }

  #val(row, col) {
    return col.value ? col.value(row) : row[col.key];
  }

  #visible() {
    const { q, sort, filters } = this.state;
    let rows = this.rows.filter(
      (r) =>
        (!q ||
          this.o.searchKeys.some((k) =>
            String(r[k] ?? "")
              .toLowerCase()
              .includes(q.toLowerCase()),
          )) &&
        Object.entries(filters).every(([k, v]) => !v || String(r[k]) === v),
    );
    if (sort) {
      const col = this.o.columns.find((c) => c.key === sort.key);
      rows = [...rows].sort((a, b) => {
        const x = this.#val(a, col) ?? "";
        const y = this.#val(b, col) ?? "";
        return (
          (typeof x === "number" && typeof y === "number"
            ? x - y
            : String(x).localeCompare(String(y))) *
          (sort.dir === "asc" ? 1 : -1)
        );
      });
    }
    return rows;
  }

  #shell() {
    const { filters, bulkActions } = this.o;
    mount(
      this.root,
      html` <div class="oga-dt__toolbar">
          <div class="oga-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
              type="search"
              class="form-control"
              placeholder="Search…"
              aria-label="Search"
              data-dt="search"
            />
          </div>
          ${filters.map(
            (f) =>
              html`<select
                class="form-select"
                aria-label="${f.label}"
                data-dt-filter="${f.key}"
              >
                <option value="">${f.label}: all</option>
                ${f.options.map(
                  (o) => html`<option value="${o.value}">${o.label}</option>`,
                )}
              </select>`,
          )}
          ${bulkActions.length
            ? html`<div class="oga-dt__bulk d-none" data-dt="bulk">
                <span class="small" data-dt="bulk-count"></span>
                ${bulkActions.map(
                  (b) =>
                    html`<button
                      class="btn btn-ghost btn-sm"
                      data-bulk="${b.key}"
                    >
                      <i class="fa-solid ${b.icon} me-1"></i>${b.label}
                    </button>`,
                )}
              </div>`
            : ""}
        </div>
        <div class="table-responsive" data-dt="table"></div>
        <div class="oga-dt__footer">
          <span data-dt="count"></span>
          <div class="oga-dt__pager" data-dt="pager"></div>
        </div>`,
    );

    const $ = (s) => this.root.querySelector(s);
    $('[data-dt="search"]').addEventListener("input", (e) => {
      this.state.q = e.target.value.trim();
      this.state.page = 1;
      this.update();
    });
    this.root.querySelectorAll("[data-dt-filter]").forEach((el) =>
      el.addEventListener("change", () => {
        this.state.filters[el.dataset.dtFilter] = el.value;
        this.state.page = 1;
        this.update();
      }),
    );
    this.root.addEventListener("click", (e) => {
      const th = e.target.closest("th[data-sort]");
      if (th) {
        const s = this.state.sort;
        this.state.sort = {
          key: th.dataset.sort,
          dir: s?.key === th.dataset.sort && s.dir === "asc" ? "desc" : "asc",
        };
        this.update();
        return;
      }
      const page = e.target.closest("[data-dt-page]");
      if (page) {
        this.state.page = Number(page.dataset.dtPage);
        this.update();
        return;
      }
      const act = e.target.closest("[data-action]");
      if (act) {
        this.o.onAction?.(
          act.dataset.action,
          this.rows.find((r) => String(r.id) === act.dataset.id),
        );
        return;
      }
      const bulk = e.target.closest("[data-bulk]");
      if (bulk) {
        this.o.onBulk?.(
          bulk.dataset.bulk,
          this.rows.filter((r) => this.state.selected.has(String(r.id))),
        );
      }
    });
    this.root.addEventListener("change", (e) => {
      const id = e.target.dataset.select;
      if (e.target.dataset.selectAll !== undefined) {
        this.#pageRows().forEach((r) =>
          e.target.checked
            ? this.state.selected.add(String(r.id))
            : this.state.selected.delete(String(r.id)),
        );
        this.update();
      } else if (id) {
        e.target.checked
          ? this.state.selected.add(id)
          : this.state.selected.delete(id);
        this.#syncBulk();
      }
    });
  }

  #pageRows() {
    const all = this.#visible();
    const { page } = this.state;
    const n = this.o.pageSize;
    return all.slice((page - 1) * n, page * n);
  }

  #syncBulk() {
    const bar = this.root.querySelector('[data-dt="bulk"]');
    if (!bar) return;
    const n = this.state.selected.size;
    bar.classList.toggle("d-none", n === 0);
    bar.querySelector('[data-dt="bulk-count"]').textContent = `${n} selected`;
  }

  update() {
    const all = this.#visible();
    const n = this.o.pageSize;
    const pages = Math.max(1, Math.ceil(all.length / n));
    this.state.page = Math.min(this.state.page, pages);
    const rows = this.#pageRows();
    const { sort, selected } = this.state;
    const selectable = this.o.bulkActions.length > 0;

    mount(
      this.root.querySelector('[data-dt="table"]'),
      rows.length
        ? html` <table class="table oga-table">
            <thead>
              <tr>
                ${selectable
                  ? html`<th style="width:2.5rem">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        data-select-all
                        aria-label="Select all on this page"
                        ${rows.every((r) => selected.has(String(r.id)))
                          ? "checked"
                          : ""}
                      />
                    </th>`
                  : ""}
                ${this.o.columns.map(
                  (c) =>
                    html`<th
                      ${c.sort
                        ? html`data-sort="${c.key}"
                          aria-sort="${sort?.key === c.key
                            ? sort.dir === "asc"
                              ? "ascending"
                              : "descending"
                            : "none"}"`
                        : ""}
                    >
                      ${c.label}${sort?.key === c.key
                        ? html` <i
                            class="fa-solid fa-arrow-${sort.dir === "asc"
                              ? "up"
                              : "down"} ms-1"
                          ></i>`
                        : ""}
                    </th>`,
                )}
                ${this.o.actions.length
                  ? html`<th class="text-end">Actions</th>`
                  : ""}
              </tr>
            </thead>
            <tbody>
              ${rows.map(
                (r) =>
                  html`<tr>
                    ${selectable
                      ? html`<td>
                          <input
                            class="form-check-input"
                            type="checkbox"
                            data-select="${r.id}"
                            aria-label="Select row"
                            ${selected.has(String(r.id)) ? "checked" : ""}
                          />
                        </td>`
                      : ""}
                    ${this.o.columns.map(
                      (c) =>
                        html`<td class="${c.class ?? ""}">
                          ${c.render ? c.render(r) : (this.#val(r, c) ?? "—")}
                        </td>`,
                    )}
                    ${this.o.actions.length
                      ? html`<td>
                          <div class="oga-actions">
                            ${this.o.actions
                              .filter((a) => !a.when || a.when(r))
                              .map(
                                (a) =>
                                  html`<button
                                    class="btn btn-${a.variant ?? "ghost"}"
                                    data-action="${a.key}"
                                    data-id="${r.id}"
                                    title="${a.label}"
                                    aria-label="${a.label}"
                                  >
                                    <i class="fa-solid ${a.icon}"></i>
                                  </button>`,
                              )}
                          </div>
                        </td>`
                      : ""}
                  </tr>`,
              )}
            </tbody>
          </table>`
        : html`<div class="oga-dt__empty">
            <i class="fa-regular fa-folder-open"></i>${this.rows.length
              ? "No results match your filters."
              : this.o.empty}
          </div>`,
    );

    this.root.querySelector('[data-dt="count"]').textContent = all.length
      ? `Showing ${(this.state.page - 1) * n + 1}–${Math.min(this.state.page * n, all.length)} of ${all.length}`
      : "0 results";
    mount(
      this.root.querySelector('[data-dt="pager"]'),
      pages > 1
        ? html` <button
              class="btn btn-ghost"
              data-dt-page="${this.state.page - 1}"
              ${this.state.page === 1 ? "disabled" : ""}
              aria-label="Previous page"
            >
              ‹
            </button>
            ${Array.from({ length: pages }, (_, i) => i + 1).map(
              (p) =>
                html`<button
                  class="btn ${p === this.state.page
                    ? "btn-gold"
                    : "btn-ghost"}"
                  data-dt-page="${p}"
                  ${p === this.state.page ? 'aria-current="page"' : ""}
                >
                  ${p}
                </button>`,
            )}
            <button
              class="btn btn-ghost"
              data-dt-page="${this.state.page + 1}"
              ${this.state.page === pages ? "disabled" : ""}
              aria-label="Next page"
            >
              ›
            </button>`
        : "",
    );
    this.#syncBulk();
  }
}
