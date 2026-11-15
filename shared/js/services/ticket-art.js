import { loadQr } from "../components/flier.js";

/**
 * Draws ONE ticket (one seat) as an image. The page shows this image and the Download / Share buttons give out
 * exactly the same picture, so what people see is what they send. The tier word, colours, pattern and detail
 * lines come from the ticket type's look, which the admin sets, so a VIP, Platinum or Regular ticket is
 * recognisable at a glance. Nothing in here decides who may enter: the QR code carries only the ticket code.
 *
 * t = { code, holder, typeName, seat, seats, groupCode, tableLabel, used, void,
 *       look: { label, primary, secondary, text, pattern, details[] }, event: { name, edition } }
 */
export const TICKET_W = 1200;
export const TICKET_H = 520;
const STUB_X = 880;
const BAND_H = 96;
const SERIF = '"Playfair Display", Georgia, serif';
const SANS = '"DM Sans", "Segoe UI", Arial, sans-serif';

const lum = (hex) => {
  const c = [1, 3, 5].map((i) => {
    const v = parseInt(hex.slice(i, i + 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
/** Black or white, whichever reads better on this background. */
export const readableOn = (bg) => (lum(bg) > 0.4 ? "#111111" : "#ffffff");

function fit(ctx, text, maxW, size, min, weight, family) {
  let s = size;
  ctx.font = `${weight} ${s}px ${family}`;
  while (ctx.measureText(text).width > maxW && s > min) {
    s -= 2;
    ctx.font = `${weight} ${s}px ${family}`;
  }
  if (ctx.measureText(text).width <= maxW) return text;
  let out = text;
  while (out.length > 1 && ctx.measureText(`${out}…`).width > maxW)
    out = out.slice(0, -1);
  return `${out.trimEnd()}…`;
}

function spaced(ctx, px) {
  if ("letterSpacing" in ctx) ctx.letterSpacing = `${px}px`;
}

function pattern(ctx, kind, color) {
  ctx.save();
  ctx.strokeStyle = color;
  ctx.fillStyle = color;
  if (kind === "stripes") {
    ctx.globalAlpha = 0.09;
    for (let x = 0; x < TICKET_W; x += 30) ctx.fillRect(x, 0, 12, TICKET_H);
  } else if (kind === "dots") {
    ctx.globalAlpha = 0.14;
    for (let y = 14; y < TICKET_H; y += 24)
      for (let x = 14; x < TICKET_W; x += 24) {
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fill();
      }
  } else if (kind === "diagonal") {
    ctx.globalAlpha = 0.11;
    ctx.lineWidth = 7;
    for (let x = -TICKET_H; x < TICKET_W; x += 34) {
      ctx.beginPath();
      ctx.moveTo(x, TICKET_H);
      ctx.lineTo(x + TICKET_H, 0);
      ctx.stroke();
    }
  } else if (kind === "rings") {
    ctx.globalAlpha = 0.1;
    ctx.lineWidth = 6;
    for (let r = 60; r < 900; r += 64) {
      ctx.beginPath();
      ctx.arc(STUB_X - 40, TICKET_H + 40, r, 0, Math.PI * 2);
      ctx.stroke();
    }
  }
  ctx.restore();
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

async function qrOn(ctx, code, x, y, size) {
  try {
    const qr = (await loadQr())(0, "M");
    qr.addData(code);
    qr.make();
    const n = qr.getModuleCount();
    const cell = Math.max(3, Math.floor(size / n)); // whole pixels: modules stay square and the code stays scannable
    const off = (size - cell * n) / 2;
    ctx.fillStyle = "#000000";
    for (let r = 0; r < n; r++)
      for (let c = 0; c < n; c++)
        if (qr.isDark(r, c))
          ctx.fillRect(x + off + c * cell, y + off + r * cell, cell, cell);
  } catch {
    ctx.strokeStyle = "#999999";
    ctx.strokeRect(x, y, size, size);
    ctx.fillStyle = "#444444";
    ctx.font = `600 20px ${SANS}`;
    ctx.textAlign = "center";
    ctx.fillText("QR unavailable", x + size / 2, y + size / 2 - 8);
    ctx.fillText("show the code", x + size / 2, y + size / 2 + 22);
    ctx.textAlign = "left";
  }
}

/** Paints the ticket into `canvas`. scale 1 = full size (1200 x 520); use 0.5 for previews. */
export async function drawTicket(canvas, t, { scale = 1 } = {}) {
  try {
    await Promise.all([
      document.fonts.load(`700 52px ${SERIF}`),
      document.fonts.load(`700 40px ${SANS}`),
    ]);
  } catch {
    /* fonts are a nicety: the fallbacks look fine */
  }
  canvas.width = Math.round(TICKET_W * scale);
  canvas.height = Math.round(TICKET_H * scale);
  const ctx = canvas.getContext("2d");
  ctx.setTransform(scale, 0, 0, scale, 0, 0);
  ctx.clearRect(0, 0, TICKET_W, TICKET_H);
  const L = t.look ?? {};
  const primary = L.primary ?? "#0f1b3d";
  const secondary = L.secondary ?? "#c9a227";
  const text = L.text ?? "#ffffff";

  ctx.save();
  roundRect(ctx, 0, 0, TICKET_W, TICKET_H, 28);
  ctx.clip();
  // body
  ctx.fillStyle = primary;
  ctx.fillRect(0, 0, STUB_X, TICKET_H);
  pattern(ctx, L.pattern ?? "none", secondary);
  // stub
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(STUB_X, 0, TICKET_W - STUB_X, TICKET_H);
  // tier band across the top: the first thing the eye sees
  ctx.fillStyle = secondary;
  ctx.fillRect(0, 0, TICKET_W, BAND_H);
  ctx.fillStyle = readableOn(secondary);
  ctx.textBaseline = "middle";
  spaced(ctx, 7);
  ctx.font = `800 58px ${SANS}`;
  ctx.fillText(
    fit(
      ctx,
      (L.label || t.typeName || "").toUpperCase(),
      560,
      58,
      30,
      800,
      SANS,
    ),
    56,
    BAND_H / 2 + 3,
  );
  spaced(ctx, 4);
  ctx.font = `700 24px ${SANS}`;
  ctx.textAlign = "right";
  const seatLine = t.seats > 1 ? `SEAT ${t.seat} OF ${t.seats}` : "ADMIT ONE";
  ctx.fillText(seatLine, STUB_X - 40, BAND_H / 2 + 2);
  ctx.textAlign = "left";
  spaced(ctx, 0);
  ctx.textBaseline = "alphabetic";

  // event
  ctx.fillStyle = text;
  ctx.font = `700 54px ${SERIF}`;
  ctx.fillText(
    fit(ctx, t.event?.name ?? "", STUB_X - 112, 54, 30, 700, SERIF),
    56,
    186,
  );
  ctx.fillStyle = secondary;
  ctx.font = `600 30px ${SANS}`;
  ctx.fillText(
    fit(ctx, t.event?.edition ?? "", STUB_X - 112, 30, 18, 600, SANS),
    56,
    228,
  );
  // detail lines (date, venue…), set by the admin
  ctx.fillStyle = text;
  ctx.globalAlpha = 0.88;
  ctx.font = `500 26px ${SANS}`;
  (L.details ?? [])
    .slice(0, 3)
    .forEach((d, i) =>
      ctx.fillText(
        fit(ctx, d, STUB_X - 112, 26, 18, 500, SANS),
        56,
        276 + i * 34,
      ),
    );
  ctx.globalAlpha = 1;
  // holder
  ctx.fillStyle = text;
  ctx.globalAlpha = 0.7;
  spaced(ctx, 4);
  ctx.font = `600 18px ${SANS}`;
  ctx.fillText("ADMIT", 56, 396);
  spaced(ctx, 0);
  ctx.globalAlpha = 1;
  ctx.font = `700 46px ${SANS}`;
  ctx.fillText(
    fit(ctx, t.holder ?? "", STUB_X - 112, 46, 26, 700, SANS),
    56,
    442,
  );
  // footer
  ctx.globalAlpha = 0.8;
  ctx.font = `500 22px ui-monospace, Menlo, Consolas, monospace`;
  const foot = [t.code, t.tableLabel, t.seats > 1 ? t.groupCode : ""]
    .filter(Boolean)
    .join("  ·  ");
  ctx.fillText(
    fit(
      ctx,
      foot,
      STUB_X - 112,
      22,
      14,
      500,
      "ui-monospace, Menlo, Consolas, monospace",
    ),
    56,
    490,
  );
  ctx.globalAlpha = 1;

  // stub: QR and code
  await qrOn(ctx, t.code, STUB_X + 48, 126, 224);
  ctx.fillStyle = "#111111";
  ctx.textAlign = "center";
  spaced(ctx, 3);
  ctx.font = `700 17px ${SANS}`;
  ctx.fillText("SCAN AT THE GATE", STUB_X + 160, 388);
  spaced(ctx, 0);
  ctx.font = `500 18px ui-monospace, Menlo, Consolas, monospace`;
  ctx.fillText(t.code, STUB_X + 160, 424);
  ctx.fillStyle = "#666666";
  ctx.font = `500 16px ${SANS}`;
  ctx.fillText("One entry per code", STUB_X + 160, 456);
  ctx.textAlign = "left";
  // perforation
  ctx.strokeStyle = "rgba(0,0,0,.28)";
  ctx.lineWidth = 3;
  ctx.setLineDash([10, 9]);
  ctx.beginPath();
  ctx.moveTo(STUB_X, BAND_H + 10);
  ctx.lineTo(STUB_X, TICKET_H - 10);
  ctx.stroke();
  ctx.setLineDash([]);

  // a used or cancelled ticket is stamped so nobody shares it by mistake
  if (t.void || t.used) {
    ctx.save();
    ctx.translate(TICKET_W / 2, TICKET_H / 2 + 20);
    ctx.rotate(-0.22);
    ctx.globalAlpha = 0.82;
    ctx.fillStyle = "#b3261e";
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 10;
    ctx.textAlign = "center";
    ctx.font = `900 150px ${SANS}`;
    const word = t.void ? "CANCELLED" : "USED";
    ctx.strokeText(word, 0, 50);
    ctx.fillText(word, 0, 50);
    ctx.restore();
  }
  ctx.restore();
  // ticket-stub notches, cut out so they are see-through in the saved image
  ctx.save();
  ctx.globalCompositeOperation = "destination-out";
  [0, TICKET_H].forEach((y) => {
    ctx.beginPath();
    ctx.arc(STUB_X, y, 20, 0, Math.PI * 2);
    ctx.fill();
  });
  ctx.restore();
  return canvas;
}

const slug = (s) =>
  String(s ?? "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "") || "ticket";

/** A file name people can tell apart in a downloads folder: event-tier-seat-holder-code.png */
export const ticketFileName = (t) =>
  `${slug(t.event?.name)}-${slug(t.look?.label || t.typeName)}${t.seats > 1 ? `-seat${t.seat}of${t.seats}` : ""}-${slug(t.holder)}-${t.code.toLowerCase()}.png`;

export async function ticketBlob(t) {
  const canvas = document.createElement("canvas");
  await drawTicket(canvas, t);
  return new Promise((resolve, reject) =>
    canvas.toBlob(
      (b) => (b ? resolve(b) : reject(new Error("Could not make the image."))),
      "image/png",
    ),
  );
}

function save(blob, name) {
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement("a"), {
    href: url,
    download: name,
  });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}

/** One seat, one image file. */
export async function downloadTicket(t) {
  save(await ticketBlob(t), ticketFileName(t));
}

/** Can this phone hand image files straight to WhatsApp and friends? */
export const canShareImage = () => {
  try {
    return Boolean(
      navigator.canShare?.({
        files: [new File([""], "t.png", { type: "image/png" })],
      }),
    );
  } catch {
    return false;
  }
};

/** Opens the phone's share sheet with the ticket image; falls back to a download. */
export async function shareTicket(t) {
  const blob = await ticketBlob(t);
  const file = new File([blob], ticketFileName(t), { type: "image/png" });
  if (canShareImage()) {
    try {
      await navigator.share({
        files: [file],
        title: `${t.look?.label ?? t.typeName} ticket`,
        text: `${t.event?.name ?? ""} ${t.event?.edition ?? ""}`.trim(),
      });
      return;
    } catch (e) {
      if (e?.name === "AbortError") return;
    }
  }
  save(blob, file.name);
}

/** Every seat as its own separate file (a table for 4 gives 4 images). onProgress(done, total). */
export async function downloadEach(list, onProgress = () => {}) {
  for (let i = 0; i < list.length; i++) {
    await downloadTicket(list[i]);
    onProgress(i + 1, list.length);
    await new Promise((r) => setTimeout(r, 450)); // browsers drop downloads fired too fast
  }
}

/**
 * Fills every `<canvas data-ticket="CODE">` under `root` with a small preview of that ticket, drawing each one only
 * when it scrolls into view, so a long batch stays quick. `lookup(code)` returns the ticket.
 */
export function previewTickets(root, lookup, scale = 0.5) {
  const draw = (el) => {
    const t = lookup(el.dataset.ticket);
    if (t) drawTicket(el, t, { scale });
  };
  if (!("IntersectionObserver" in window))
    return root.querySelectorAll("canvas[data-ticket]").forEach(draw);
  const io = new IntersectionObserver(
    (entries) =>
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        draw(e.target);
      }),
    { rootMargin: "300px" },
  );
  root.querySelectorAll("canvas[data-ticket]").forEach((el) => io.observe(el));
}
