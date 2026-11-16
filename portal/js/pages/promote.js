import { html } from "../../../shared/js/core/dom.js";
import { notify } from "../../../shared/js/ui/notify.js";
import {
  FLIER_SIZES,
  drawFlier,
  downloadFlier,
} from "../../../shared/js/components/flier.js";
import { card, emptyState } from "../ui.js";
import { drawQrCard } from "../qrcard.js";

export const title = "Share & promote";

export default function promote(p) {
  const { me, ctx, cat, readOnly, profileUrl } = p;
  const approved = me.status === "approved";
  const text = `Vote for me, ${me.name}, in the ${ctx.occasion.name} ${ctx.occasion.edition}${cat ? ` (${cat.name})` : ""}!`;
  const enc = encodeURIComponent;
  const flier =
    ctx.occasion.flier?.enabled && me.occasionId === ctx.occasion.id;

  return html`<div class="row g-4">
    <div class="col-12">
      ${approved
        ? card(
            "Your public link",
            html`<div class="row g-2">
                <div class="col-md-9">
                  <input
                    class="form-control"
                    id="profileUrl"
                    readonly
                    value="${profileUrl}"
                    aria-label="Your public profile link"
                  />
                </div>
                <div class="col-md-3">
                  <button class="btn btn-gold w-100" id="copyBtn" type="button">
                    <i class="fa-solid fa-copy me-2"></i>Copy link
                  </button>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2 mt-3">
                <a
                  class="btn btn-ghost btn-sm"
                  target="_blank"
                  rel="noopener"
                  href="https://wa.me/?text=${enc(`${text} ${profileUrl}`)}"
                  ><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a
                >
                <a
                  class="btn btn-ghost btn-sm"
                  target="_blank"
                  rel="noopener"
                  href="https://twitter.com/intent/tweet?text=${enc(
                    text,
                  )}&url=${enc(profileUrl)}"
                  ><i class="fa-brands fa-x-twitter me-1"></i>Post</a
                >
                <a
                  class="btn btn-ghost btn-sm"
                  target="_blank"
                  rel="noopener"
                  href="https://www.facebook.com/sharer/sharer.php?u=${enc(
                    profileUrl,
                  )}"
                  ><i class="fa-brands fa-facebook me-1"></i>Facebook</a
                >
                <button
                  class="btn btn-ghost btn-sm"
                  id="shareBtn"
                  type="button"
                  hidden
                >
                  <i class="fa-solid fa-share-nodes me-1"></i>Share…
                </button>
                <button
                  class="btn btn-ghost btn-sm"
                  id="copyText"
                  type="button"
                >
                  <i class="fa-solid fa-quote-left me-1"></i>Copy message
                </button>
              </div>
              <p class="small text-secondary mt-3 mb-0">${text}</p>`,
            { icon: "fa-link" },
          )
        : card(
            "Your public link",
            emptyState(
              "fa-lock",
              "Unlocks when you’re approved",
              "Once your entry is approved you’ll get a link, share buttons and a QR code here.",
            ),
            { icon: "fa-link" },
          )}
    </div>

    ${flier
      ? html`<div class="col-lg-6">
          ${card(
            "Promo flier",
            html`<p class="small text-secondary">
                A ready-made flier with your photo, name and a QR code to your
                profile.${me.photo
                  ? ""
                  : " Add a profile photo to make it stand out."}
              </p>
              <div class="d-flex flex-wrap gap-2 mb-3">
                <select
                  class="form-select form-select-sm w-auto"
                  id="flierSize"
                  aria-label="Flier size"
                >
                  ${Object.entries(FLIER_SIZES).map(
                    ([k, v]) => html`<option value="${k}">${v.label}</option>`,
                  )}
                </select>
                <button
                  class="btn btn-gold btn-sm"
                  id="flierDownload"
                  type="button"
                >
                  <i class="fa-solid fa-download me-1"></i>Download PNG
                </button>
              </div>
              <canvas
                id="flierCanvas"
                class="w-100 rounded"
                style="max-width:340px;height:auto"
                aria-label="Preview of your promo flier"
              ></canvas>`,
            { icon: "fa-image" },
          )}
        </div>`
      : ""}
    ${approved
      ? html`<div class="col-lg-6">
          ${card(
            "QR code card",
            html`<p class="small text-secondary">
                A square-ish card with a big QR code. Good for status posts,
                stickers and posters. Anyone who scans it lands on your profile.
              </p>
              <button
                class="btn btn-gold btn-sm mb-3"
                id="qrDownload"
                type="button"
              >
                <i class="fa-solid fa-download me-1"></i>Download PNG
              </button>
              <canvas
                id="qrCanvas"
                class="w-100 rounded"
                style="max-width:340px;height:auto"
                aria-label="Preview of your QR code card"
              ></canvas>`,
            { icon: "fa-qrcode" },
          )}
        </div>`
      : ""}
  </div>`;
}

export async function init(root, p) {
  const { me, ctx, cat, profileUrl } = p;
  const safe = me.name.replace(/[^a-z0-9]+/gi, "-").toLowerCase();
  root.querySelector("#copyBtn")?.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(profileUrl);
      notify.toast("success", "Link copied");
    } catch {
      root.querySelector("#profileUrl").select();
    }
  });
  root.querySelector("#copyText")?.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(
        `Vote for me, ${me.name}, in the ${ctx.occasion.name} ${ctx.occasion.edition}${cat ? ` (${cat.name})` : ""}! ${profileUrl}`,
      );
      notify.toast("success", "Message copied");
    } catch {
      notify.warning(
        "Couldn’t copy",
        "Select the message and copy it by hand.",
      );
    }
  });
  const share = root.querySelector("#shareBtn");
  if (share && navigator.share) {
    share.hidden = false;
    share.addEventListener("click", () =>
      navigator
        .share({
          title: me.name,
          text: `Vote for me, ${me.name}!`,
          url: profileUrl,
        })
        .catch(() => {}),
    );
  }

  const flier = root.querySelector("#flierCanvas");
  if (flier) {
    const draw = () =>
      drawFlier(flier, {
        size: root.querySelector("#flierSize").value,
        cfg: ctx.occasion.flier,
        name: me.name,
        nickname: me.nickname,
        category: cat?.name ?? "",
        edition: `${ctx.occasion.name} ${ctx.occasion.edition}`,
        photo: me.photo,
        voteUrl: profileUrl,
      });
    draw();
    root.querySelector("#flierSize").addEventListener("change", draw);
    root.querySelector("#flierDownload").addEventListener("click", async () => {
      try {
        await draw();
        await downloadFlier(flier, `${safe}-flier.png`);
      } catch (err) {
        await notify.warning("Couldn’t create the flier", err.message);
      }
    });
  }

  const qr = root.querySelector("#qrCanvas");
  if (qr) {
    const draw = () =>
      drawQrCard(qr, {
        name: me.nickname || me.name,
        category: cat?.name ?? "",
        edition: `${ctx.occasion.name} ${ctx.occasion.edition}`,
        url: profileUrl,
      });
    draw();
    root.querySelector("#qrDownload").addEventListener("click", async () => {
      try {
        await draw();
        await downloadFlier(qr, `${safe}-qr-card.png`);
      } catch (err) {
        await notify.warning("Couldn’t create the card", err.message);
      }
    });
  }
}
