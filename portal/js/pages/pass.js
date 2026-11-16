import { html } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  getAccessPass,
  claimAccessPass,
} from "../../../shared/js/services/auth.service.js";
import {
  previewTickets,
  downloadTicket,
  shareTicket,
  downloadEach,
  canShareImage,
} from "../../../shared/js/services/ticket-art.js";
import { card, emptyState } from "../ui.js";

export const title = "Access Pass";

let TICKETS = [];
const find = (code) => TICKETS.find((t) => t.code === code);

/** The pass, shaped for the ticket image: every seat is its own ticket. */
const shape = (st) =>
  st.tickets.map((t) => ({
    code: t.code,
    holder: t.holder,
    seat: t.seat,
    seats: st.tickets.length,
    groupCode: "",
    tableLabel: null,
    used: t.used,
    void: t.void,
    typeName: st.typeName ?? "Access Pass",
    look: st.look,
    event: st.event ?? { name: "", edition: "" },
  }));

export default async function pass(p) {
  if (p.readOnly)
    return card(
      "Access Pass",
      emptyState(
        "fa-eye",
        "Not shown in preview",
        "A nominee’s Access Pass is private to them.",
      ),
      { icon: "fa-ticket" },
    );
  let st;
  try {
    st = await getAccessPass();
  } catch (err) {
    return card(
      "Access Pass",
      emptyState(
        "fa-circle-exclamation",
        "We couldn’t load your pass",
        err.message,
      ),
      { icon: "fa-ticket" },
    );
  }
  TICKETS = shape(st);
  if (!st.enabled && !st.claimed)
    return card(
      "Access Pass",
      emptyState(
        "fa-ticket",
        "No Access Pass for this edition",
        "The organisers have not opened a nominee pass.",
      ),
      { icon: "fa-ticket" },
    );

  if (!st.claimed)
    return card(
      "Your Nominee Access Pass",
      st.eligible
        ? html`<p>
              You’re approved, so your event entry is
              free${st.guests
                ? `. The pass admits you and ${st.guests} guest${st.guests === 1 ? "" : "s"}`
                : ""}.
              Every seat gets its own ticket with its own QR code.
            </p>
            <button class="btn btn-gold" id="passClaim" type="button">
              <i class="fa-solid fa-ticket me-2"></i>Claim my Access Pass
            </button>`
        : emptyState(
            "fa-hourglass-half",
            "Opens once you’re approved",
            "As soon as one of your entries is approved you can claim your free pass here.",
          ),
      { icon: "fa-ticket" },
    );

  const live = TICKETS.filter((t) => !t.used && !t.void);
  return html`<p class="text-secondary">
      Show a QR code at the gate. Each code is scanned once. You’re invited
      whether or not you win; winners are announced at the event. Send a guest’s
      ticket to them as an image.
    </p>
    ${live.length > 1
      ? html`<div class="d-flex flex-wrap gap-2 mb-3 no-print">
          <button class="btn btn-gold" id="passAll">
            <i class="fa-solid fa-download me-2"></i>Download all ${live.length}
            as separate images</button
          ><button class="btn btn-ghost" id="passPrint">
            <i class="fa-solid fa-print me-2"></i>Print
          </button>
        </div>`
      : html`<div class="mb-3 no-print">
          <button class="btn btn-ghost" id="passPrint">
            <i class="fa-solid fa-print me-2"></i>Print
          </button>
        </div>`}
    <div class="row g-4">
      ${TICKETS.map(
        (t) =>
          html`<div class="col-xl-6">
            <canvas
              data-ticket="${t.code}"
              width="600"
              height="260"
              style="width:100%;height:auto;border-radius:1rem"
              aria-label="Access pass ticket for ${t.holder}"
            ></canvas>
            <div class="d-flex flex-wrap gap-2 mt-2 no-print">
              ${t.used || t.void
                ? html`<span class="pt-chip"
                    >${t.void ? "Cancelled" : "Used at the gate"}</span
                  >`
                : html`<button
                      class="btn btn-gold btn-sm"
                      data-act="download"
                      data-code="${t.code}"
                    >
                      <i class="fa-solid fa-image me-1"></i>Download image</button
                    >${canShareImage()
                      ? html`<button
                          class="btn btn-ghost btn-sm"
                          data-act="share"
                          data-code="${t.code}"
                        >
                          <i class="fa-solid fa-share-nodes me-1"></i>Share
                        </button>`
                      : ""}`}
            </div>
          </div>`,
      )}
    </div>`;
}

export function init(root, p) {
  if (p.readOnly) return;
  previewTickets(root, find, 0.5);
  root.querySelector("#passPrint")?.addEventListener("click", () => print());
  root.querySelector("#passClaim")?.addEventListener("click", async (e) => {
    e.currentTarget.disabled = true;
    try {
      await claimAccessPass();
      notify.toast("success", "Your Access Pass is ready");
      location.reload();
    } catch (err) {
      e.currentTarget.disabled = false;
      await notify.warning("Couldn’t claim it", err.message);
    }
  });
  root.querySelector("#passAll")?.addEventListener("click", async (e) => {
    const b = e.currentTarget;
    const label = b.innerHTML;
    b.disabled = true;
    try {
      await downloadEach(
        TICKETS.filter((t) => !t.used && !t.void),
        (d, n) => (b.textContent = `Saving ${d} of ${n}…`),
      );
    } catch (err) {
      notify.warning("Couldn’t save the images", err.message);
    } finally {
      b.innerHTML = label;
      b.disabled = false;
    }
  });
  root.addEventListener("click", async (e) => {
    const b = e.target.closest("[data-act]");
    if (!b) return;
    const t = find(b.dataset.code);
    try {
      if (b.dataset.act === "download") await downloadTicket(t);
      if (b.dataset.act === "share") await shareTicket(t);
    } catch (err) {
      notify.warning("That didn’t work", err.message);
    }
  });
}
