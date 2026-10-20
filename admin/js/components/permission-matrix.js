import { html } from "../../../shared/js/core/dom.js";
import { PERMISSIONS, AREAS } from "../config/permissions.js";

/**
 * Role editor: every permission as a checkbox, grouped by area.
 * `lock` = keys that can't be changed by the current editor (shown disabled).
 */
export const roleMatrix = ({
  selected = new Set(),
  disabled = false,
  lock = new Set(),
} = {}) =>
  html` <div class="oga-matrix">
    ${AREAS.map(
      (area) =>
        html` <fieldset class="oga-matrix__group">
          <legend>${area}</legend>
          ${PERMISSIONS.filter((p) => p.area === area).map(
            (p) =>
              html` <label class="oga-matrix__row"
                ><input
                  class="form-check-input"
                  type="checkbox"
                  name="perm"
                  value="${p.key}"
                  ${selected.has(p.key) ? "checked" : ""}
                  ${disabled || lock.has(p.key) ? "disabled" : ""}
                />
                <span
                  >${p.label}${p.kind === "read"
                    ? ""
                    : html` <span class="oga-tag"
                        >changes data</span
                      >`}${p.scope === "global"
                    ? html` <span class="oga-tag">all editions</span>`
                    : ""}</span
                ></label
              >`,
          )}
        </fieldset>`,
    )}
  </div>`;

export const readRoleMatrix = (root) =>
  [...root.querySelectorAll('input[name="perm"]:checked')].map((i) => i.value);

/**
 * Per-person tweaks on top of roles: "Allow" adds a permission, "Block" removes it even if a role gives it.
 * `fromRoles` = keys already given by the person's roles. `grantable` = keys the editor may grant.
 */
export const overrideMatrix = ({
  fromRoles = new Set(),
  grants = new Set(),
  denies = new Set(),
  grantable = new Set(),
} = {}) =>
  html` <div class="oga-matrix oga-matrix--overrides">
    ${AREAS.map(
      (area) =>
        html` <fieldset class="oga-matrix__group">
          <legend>${area}</legend>
          ${PERMISSIONS.filter((p) => p.area === area).map(
            (p) =>
              html` <div
                class="oga-matrix__row oga-matrix__row--ov"
                data-perm="${p.key}"
                data-grantable="${grantable.has(p.key)}"
              >
                <span
                  >${p.label}
                  <span
                    class="oga-tag oga-tag--ok"
                    data-fromrole
                    ${fromRoles.has(p.key) ? "" : "hidden"}
                    >from roles</span
                  ></span
                >
                <label title="Allow, even if no role gives it"
                  ><input
                    class="form-check-input"
                    type="checkbox"
                    name="grant"
                    value="${p.key}"
                    ${grants.has(p.key) ? "checked" : ""}
                    ${fromRoles.has(p.key) || !grantable.has(p.key)
                      ? "disabled"
                      : ""}
                  />
                  Allow</label
                >
                <label title="Block, even if a role gives it"
                  ><input
                    class="form-check-input"
                    type="checkbox"
                    name="deny"
                    value="${p.key}"
                    ${denies.has(p.key) ? "checked" : ""}
                  />
                  Block</label
                >
              </div>`,
          )}
        </fieldset>`,
    )}
  </div>`;

/** Keep the "from roles" tags and Allow boxes in step with the roles currently ticked. */
export function syncOverrides(root, permsFromRoles) {
  root.querySelectorAll("[data-perm]").forEach((row) => {
    const key = row.dataset.perm;
    const give = permsFromRoles.has(key);
    row.querySelector("[data-fromrole]").hidden = !give;
    const allow = row.querySelector('input[name="grant"]');
    if (give) {
      allow.checked = false;
      allow.disabled = true;
    } else allow.disabled = row.dataset.grantable !== "true";
  });
}

export const readOverrides = (root) => ({
  grants: [...root.querySelectorAll('input[name="grant"]:checked')].map(
    (i) => i.value,
  ),
  denies: [...root.querySelectorAll('input[name="deny"]:checked')].map(
    (i) => i.value,
  ),
});
