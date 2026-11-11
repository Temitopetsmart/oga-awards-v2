import { mediaUrl } from "../core/media.js";

const QR_LIB = "https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js";

/** Loads the QR library once (the same one the ticket page uses). */
export const loadQr = () =>
  new Promise((resolve, reject) => {
    if (window.qrcode) return resolve(window.qrcode);
    const s = document.createElement("script");
    s.src = QR_LIB;
    s.onload = () => resolve(window.qrcode);
    s.onerror = () => reject(new Error("QR library failed to load"));
    document.head.append(s);
  });

export const FLIER_SIZES = {
  post: { w: 1080, h: 1350, label: "Post (4:5)" },
  story: { w: 1080, h: 1920, label: "Story (9:16)" },
};

const loadImage = (src) =>
  new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = src;
  });

/** Shrinks the text until it fits the width, then draws it centred. Returns the next y. */
function centred(ctx, text, y, maxWidth, size, minSize, weight = "700") {
  let s = size;
  do {
    ctx.font = `${weight} ${s}px "Segoe UI", Arial, sans-serif`;
    s -= 2;
  } while (ctx.measureText(text).width > maxWidth && s >= minSize);
  ctx.fillText(text, ctx.canvas.width / 2, y);
  return y;
}

/**
 * Draws the promo flier. Everything the admin controls comes from `cfg` (colours, wording, QR, category line);
 * everything personal comes from the nominee. Nothing is sent anywhere: it is drawn in the browser.
 */
export async function drawFlier(
  canvas,
  { size = "post", cfg, name, nickname, category, edition, photo, voteUrl },
) {
  const { w, h } = FLIER_SIZES[size] ?? FLIER_SIZES.post;
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  const pad = 80;
  const story = size === "story";

  ctx.fillStyle = cfg.bg;
  ctx.fillRect(0, 0, w, h);
  ctx.fillStyle = cfg.accent;
  ctx.fillRect(0, 0, w, 18);
  ctx.fillRect(0, h - 18, w, 18);
  ctx.textAlign = "center";
  ctx.textBaseline = "alphabetic";

  // headline
  let y = story ? 250 : 190;
  ctx.fillStyle = cfg.accent;
  centred(ctx, cfg.headline.toUpperCase(), y, w - pad * 2, 96, 40, "800");

  // photo
  const diameter = story ? 640 : 560;
  const cx = w / 2;
  const cy = y + 90 + diameter / 2;
  ctx.save();
  ctx.beginPath();
  ctx.arc(cx, cy, diameter / 2 + 14, 0, Math.PI * 2);
  ctx.fillStyle = cfg.accent;
  ctx.fill();
  ctx.beginPath();
  ctx.arc(cx, cy, diameter / 2, 0, Math.PI * 2);
  ctx.clip();
  const img = photo ? await loadImage(mediaUrl(photo)) : null;
  if (img) {
    const side = Math.min(img.width, img.height);
    ctx.drawImage(
      img,
      (img.width - side) / 2,
      (img.height - side) / 2,
      side,
      side,
      cx - diameter / 2,
      cy - diameter / 2,
      diameter,
      diameter,
    );
  } else {
    ctx.fillStyle = cfg.text;
    ctx.globalAlpha = 0.12;
    ctx.fillRect(cx - diameter / 2, cy - diameter / 2, diameter, diameter);
    ctx.globalAlpha = 1;
    ctx.fillStyle = cfg.text;
    ctx.font = `800 ${diameter / 2}px "Segoe UI", Arial, sans-serif`;
    ctx.textBaseline = "middle";
    ctx.fillText(
      (name.trim()[0] ?? "?").toUpperCase(),
      cx,
      cy + diameter * 0.03,
    );
    ctx.textBaseline = "alphabetic";
  }
  ctx.restore();

  // name, nickname, category, tagline
  y = cy + diameter / 2 + 130;
  ctx.fillStyle = cfg.text;
  centred(ctx, name, y, w - pad * 2, 84, 36, "800");
  if (nickname) {
    y += 70;
    ctx.fillStyle = cfg.accent;
    centred(ctx, `“${nickname}”`, y, w - pad * 2, 54, 28, "600");
  }
  if (cfg.showCategory && category) {
    y += 76;
    ctx.fillStyle = cfg.text;
    centred(ctx, category, y, w - pad * 2, 46, 24, "600");
  }
  if (cfg.tagline) {
    y += 64;
    ctx.globalAlpha = 0.85;
    ctx.fillStyle = cfg.text;
    centred(ctx, cfg.tagline, y, w - pad * 2, 38, 22, "400");
    ctx.globalAlpha = 1;
  }
  if (edition) {
    y += 58;
    ctx.globalAlpha = 0.7;
    ctx.fillStyle = cfg.text;
    centred(ctx, edition, y, w - pad * 2, 34, 20, "400");
    ctx.globalAlpha = 1;
  }

  // QR + footer, anchored to the bottom
  let bottom = h - 70;
  if (cfg.footer) {
    ctx.fillStyle = cfg.accent;
    centred(ctx, cfg.footer, bottom, w - pad * 2, 40, 22, "600");
    bottom -= 56;
  }
  if (cfg.showQr && voteUrl) {
    try {
      const qrcode = await loadQr();
      const qr = qrcode(0, "M");
      qr.addData(voteUrl);
      qr.make();
      const n = qr.getModuleCount();
      const box = story ? 360 : 280;
      const cell = Math.floor(box / n);
      const real = cell * n;
      const qx = (w - real) / 2;
      const qy = bottom - real - 20;
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(qx - 20, qy - 20, real + 40, real + 40);
      ctx.fillStyle = "#000000";
      for (let r = 0; r < n; r++)
        for (let c = 0; c < n; c++)
          if (qr.isDark(r, c))
            ctx.fillRect(qx + c * cell, qy + r * cell, cell, cell);
    } catch {
      /* no QR: the footer text still shows */
    }
  }
}

/** Saves the canvas as a PNG file. */
export const downloadFlier = (canvas, fileName) =>
  new Promise((resolve, reject) => {
    canvas.toBlob((blob) => {
      if (!blob) return reject(new Error("Could not create the image."));
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = fileName;
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 2000);
      resolve();
    }, "image/png");
  });
