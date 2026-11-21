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
  square: { w: 1080, h: 1080, label: "Square (1:1)" },
};

/** The stages a flier exists for. The server keeps the same list (App\Flier::STAGES). */
export const STAGES = [
  "landing",
  "nominations",
  "voting",
  "voting-closed",
  "results",
  "winner",
];

export const STAGE_LABELS = {
  landing: "Coming soon",
  nominations: "Nominations open",
  voting: "Voting open",
  "voting-closed": "Voting closed",
  results: "Results out",
  winner: "Winner",
};

/** Which flier a nominee gets right now: the edition's state, or "winner" once they have won. */
export const flierStage = (state, isWinner = false) =>
  state === "results" && isWinner
    ? "winner"
    : STAGES.includes(state)
      ? state
      : "voting";

/** The look and wording for a stage; older saved settings without stages still work. */
export const stageConfig = (cfg, stage) => ({
  on: true,
  headline: cfg?.headline ?? "Vote for me",
  tagline: cfg?.tagline ?? "",
  footer: cfg?.footer ?? "",
  bg: cfg?.bg ?? "#0a0a0e",
  accent: cfg?.accent ?? "#ffc633",
  text: cfg?.text ?? "#ffffff",
  ...(cfg?.states?.[stage] ?? {}),
});

/** Fills {name} {category} {edition} {opens} {closes} {results}. A line that needs a missing word is left out. */
export function fillTokens(template, vars) {
  let missing = false;
  const out = String(template ?? "").replace(/\{(\w+)\}/g, (_, k) => {
    const v = vars[k];
    if (v == null || v === "") missing = true;
    return v ?? "";
  });
  return missing ? "" : out.trim();
}

const fmtDate = (iso) =>
  iso
    ? new Date(iso).toLocaleDateString("en-GB", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : "";

// ---------------------------------------------------------------- fonts
const HEAD = '"Anton", Impact, "Arial Narrow", sans-serif';
const BODY = '"Poppins", "Segoe UI", Arial, sans-serif';
const FONT_BASE = "https://cdn.jsdelivr.net/npm/";
const FONT_FILES = [
  [
    "Anton",
    "400",
    "@fontsource/anton@5.3.0/files/anton-latin-400-normal.woff2",
  ],
  [
    "Poppins",
    "500",
    "@fontsource/poppins@5.3.0/files/poppins-latin-500-normal.woff2",
  ],
  [
    "Poppins",
    "600",
    "@fontsource/poppins@5.3.0/files/poppins-latin-600-normal.woff2",
  ],
  [
    "Poppins",
    "700",
    "@fontsource/poppins@5.3.0/files/poppins-latin-700-normal.woff2",
  ],
];
let fontsReady;
/** The poster fonts load once; if they cannot load, the fallback fonts in the stacks above are used. */
export const ensureFonts = () =>
  (fontsReady ??= Promise.allSettled(
    FONT_FILES.map(async ([family, weight, file]) => {
      const f = new FontFace(family, `url(${FONT_BASE}${file})`, { weight });
      await f.load();
      document.fonts.add(f);
    }),
  ));

const loadImage = (src) =>
  new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = src;
  });

// ---------------------------------------------------------------- small helpers
const hexA = (hex, a) => {
  const n = parseInt(String(hex).slice(1), 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
};

const roundRect = (ctx, x, y, w, h, r) => {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
};

const sparkle = (ctx, x, y, r) => {
  ctx.beginPath();
  ctx.moveTo(x, y - r);
  ctx.quadraticCurveTo(x, y, x + r, y);
  ctx.quadraticCurveTo(x, y, x, y + r);
  ctx.quadraticCurveTo(x, y, x - r, y);
  ctx.quadraticCurveTo(x, y, x, y - r);
  ctx.fill();
};

const crown = (ctx, x, y, s) => {
  ctx.beginPath();
  ctx.moveTo(x, y + s * 0.8);
  ctx.lineTo(x, y + s * 0.25);
  ctx.lineTo(x + s * 0.27, y + s * 0.55);
  ctx.lineTo(x + s * 0.5, y);
  ctx.lineTo(x + s * 0.73, y + s * 0.55);
  ctx.lineTo(x + s, y + s * 0.25);
  ctx.lineTo(x + s, y + s * 0.8);
  ctx.closePath();
  ctx.fill();
  ctx.fillRect(x, y + s * 0.88, s, s * 0.14);
};

/** The same pseudo-random dots every time, so a flier never changes when it is redrawn. */
const seeded = (seed) => () =>
  (seed = (seed * 16807) % 2147483647) / 2147483647;

function wrap(ctx, text, maxW) {
  const words = text.split(/\s+/).filter(Boolean);
  const lines = [];
  let line = "";
  for (const w of words) {
    const t = line ? `${line} ${w}` : w;
    if (line && ctx.measureText(t).width > maxW) {
      lines.push(line);
      line = w;
    } else line = t;
  }
  if (line) lines.push(line);
  return lines;
}

/** Biggest size (down to `min`) at which the text fits in `maxLines` lines. */
function fit(ctx, text, font, maxW, size, min, maxLines) {
  for (let s = size; s >= min; s -= 2) {
    ctx.font = font(s);
    const lines = wrap(ctx, text, maxW);
    if (
      lines.length <= maxLines &&
      lines.every((l) => ctx.measureText(l).width <= maxW)
    )
      return { lines, size: s };
  }
  ctx.font = font(min);
  return { lines: wrap(ctx, text, maxW).slice(0, maxLines), size: min };
}

/** Draws the photo to fill a box, like CSS object-fit: cover, keeping faces (they sit in the upper part). */
function cover(ctx, img, x, y, w, h, focusY = 0.28) {
  const k = Math.max(w / img.width, h / img.height);
  const dw = img.width * k;
  const dh = img.height * k;
  const dx = x + (w - dw) / 2;
  const dy = y - (dh - h) * focusY;
  ctx.drawImage(img, dx, dy, dw, dh);
}

// ---------------------------------------------------------------- the stage looks
// Each stage has its own picture treatment and its own decoration. Wording and colours come from the admin.
const LOOK = {
  landing: { grey: 0.55, wash: 0.5 },
  nominations: { grey: 0, wash: 0.4 },
  voting: { grey: 0, wash: 0.36, qr: 1.1 },
  "voting-closed": { grey: 1, wash: 0.5, qr: 0.8 },
  results: { grey: 0, wash: 0.42 },
  winner: { grey: 0, wash: 0.4, lift: 36 },
};

/** An x position in the outer fifth on either side, so decorations never sit on the nominee's face. */
const edgeX = (rnd, w, i) =>
  i % 2 ? 90 + rnd() * w * 0.16 : w - 90 - rnd() * w * 0.16;

function decorate(ctx, stage, c, w, h, pad, textTop) {
  const rnd = seeded(7 + STAGES.indexOf(stage) * 13);
  ctx.save();
  if (stage === "landing") {
    ctx.fillStyle = c.accent;
    [
      [300, 70],
      [190, 40],
      [100, 22],
    ].forEach(([d, t], i) => {
      ctx.beginPath();
      ctx.moveTo(w - d - t - i * 6, 0);
      ctx.lineTo(w - d, 0);
      ctx.lineTo(w, d);
      ctx.lineTo(w, d + t + i * 6);
      ctx.closePath();
      ctx.globalAlpha = 0.9 - i * 0.25;
      ctx.fill();
    });
  } else if (stage === "nominations") {
    ctx.fillStyle = c.accent;
    ctx.beginPath();
    ctx.moveTo(w, 0);
    ctx.lineTo(w - 260, 0);
    ctx.lineTo(w, 260);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = "#ffffff";
    for (let i = 0; i < 7; i++)
      sparkle(ctx, edgeX(rnd, w, i), 120 + rnd() * h * 0.3, 14 + rnd() * 22);
  } else if (stage === "voting") {
    ctx.fillStyle = c.accent;
    ctx.fillRect(0, textTop, 18, h - textTop - 58);
    ctx.fillRect(0, h - 18, w, 18);
  } else if (stage === "voting-closed") {
    ctx.fillStyle = c.accent;
    ctx.fillRect(pad, textTop - 34, 140, 8);
  } else if (stage === "results") {
    for (let i = 0; i < 46; i++) {
      ctx.globalAlpha = 0.25 + rnd() * 0.6;
      ctx.fillStyle = i % 3 ? c.accent : "#ffffff";
      ctx.beginPath();
      ctx.arc(
        edgeX(rnd, w, i),
        rnd() * h * 0.55,
        5 + rnd() * 10,
        0,
        Math.PI * 2,
      );
      ctx.fill();
    }
  } else if (stage === "winner") {
    const glow = ctx.createRadialGradient(
      w / 2,
      h * 0.3,
      40,
      w / 2,
      h * 0.3,
      w * 0.8,
    );
    glow.addColorStop(0, hexA(c.accent, 0.35));
    glow.addColorStop(1, hexA(c.accent, 0));
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = c.accent;
    ctx.lineWidth = 10;
    ctx.strokeRect(34, 34, w - 68, h - 68);
    ctx.lineWidth = 3;
    ctx.strokeRect(54, 54, w - 108, h - 108);
    ctx.fillStyle = c.accent;
    crown(ctx, w - pad - 150, 96, 150);
    ctx.fillStyle = "#ffffff";
    for (let i = 0; i < 9; i++)
      sparkle(ctx, edgeX(rnd, w, i), 150 + rnd() * h * 0.4, 12 + rnd() * 20);
  }
  ctx.restore();
}

/**
 * Draws the share-ready flier. The nominee's photo is the whole picture (not a small avatar); the wording and
 * colours of each stage come from the admin; the name, category and link are the nominee's. Drawn in the browser.
 * `deps` lets tests supply their own image, QR and font loaders.
 */
export async function drawFlier(
  canvas,
  {
    size = "post",
    cfg,
    stage = "voting",
    name,
    nickname,
    category,
    edition,
    photo,
    voteUrl,
    dates = {},
    deps = {},
  },
) {
  const d = { loadImage, loadQr, ensureFonts, ...deps };
  await d.ensureFonts();
  const { w, h } = FLIER_SIZES[size] ?? FLIER_SIZES.post;
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  const c = stageConfig(cfg, stage);
  const look = LOOK[stage] ?? LOOK.voting;
  const story = size === "story";
  const pad = 72;
  ctx.textAlign = "left";
  ctx.textBaseline = "alphabetic";

  // ---- the picture: the nominee fills the flier
  ctx.fillStyle = c.bg;
  ctx.fillRect(0, 0, w, h);
  const photoH = story ? Math.round(h * 0.8) : h;
  const img = photo ? await d.loadImage(mediaUrl(photo)) : null;
  if (img) {
    cover(ctx, img, 0, 0, w, photoH);
    if (look.grey) {
      ctx.save();
      ctx.globalCompositeOperation = "saturation";
      ctx.globalAlpha = look.grey;
      ctx.fillStyle = "#000000";
      ctx.fillRect(0, 0, w, photoH);
      ctx.restore();
    }
  } else {
    const g = ctx.createLinearGradient(0, 0, w, photoH);
    g.addColorStop(0, hexA(c.accent, 0.5));
    g.addColorStop(1, c.bg);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, photoH);
    ctx.fillStyle = hexA(c.text, 0.14);
    ctx.font = `400 ${Math.round(w * 0.9)}px ${HEAD}`;
    ctx.textAlign = "center";
    ctx.fillText((name.trim()[0] ?? "?").toUpperCase(), w / 2, photoH * 0.62);
    ctx.textAlign = "left";
  }

  // ---- colour wash so the writing is always readable
  if (photoH < h) {
    const seam = ctx.createLinearGradient(0, photoH - 340, 0, photoH);
    seam.addColorStop(0, hexA(c.bg, 0));
    seam.addColorStop(1, hexA(c.bg, 1));
    ctx.fillStyle = seam;
    ctx.fillRect(0, photoH - 340, w, 340);
  }
  let g = ctx.createLinearGradient(0, 0, 0, h * 0.22);
  g.addColorStop(0, hexA(c.bg, 0.7));
  g.addColorStop(1, hexA(c.bg, 0));
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, w, h * 0.22);
  const washTop = h * (story ? 0.4 : 0.34);
  g = ctx.createLinearGradient(0, washTop, 0, h);
  g.addColorStop(0, hexA(c.bg, 0));
  g.addColorStop(0.55, hexA(c.bg, 0.86));
  g.addColorStop(1, hexA(c.bg, 0.98));
  ctx.fillStyle = g;
  ctx.fillRect(0, washTop, w, h - washTop);
  ctx.fillStyle = hexA(c.accent, look.wash * 0.25);
  ctx.fillRect(0, 0, w, h);

  // ---- the words (a line that needs a word we do not have is left out)
  const vars = {
    name,
    category: cfg?.showCategory === false ? "" : (category ?? ""),
    edition,
    opens: fmtDate(dates.opens),
    closes: fmtDate(dates.closes),
    results: fmtDate(dates.results),
  };
  const headline = fillTokens(c.headline, vars);
  const tagline = fillTokens(c.tagline, vars);
  const footer = fillTokens(c.footer, vars);

  // the QR card (bottom right) decides how wide the text column is
  let qrDraw = null;
  const qrBox = Math.round(
    (story ? 290 : size === "square" ? 220 : 250) * (look.qr ?? 1),
  );
  if (cfg?.showQr !== false && voteUrl) {
    try {
      const qrcode = await d.loadQr();
      const qr = qrcode(0, "M");
      qr.addData(voteUrl);
      qr.make();
      qrDraw = { qr, n: qr.getModuleCount() };
    } catch {
      /* no QR: the rest still draws */
    }
  }
  const col = w - pad * 2 - (qrDraw ? qrBox + 56 : 0);
  const bottom = h - (story ? 120 : 84) - (look.lift ?? 0);

  // top line: the edition
  ctx.fillStyle = c.accent;
  ctx.fillRect(pad, 62, 10, 36);
  ctx.fillStyle = c.text;
  ctx.font = `700 32px ${BODY}`;
  let x = pad + 28;
  for (const ch of String(edition ?? "").toUpperCase()) {
    ctx.fillText(ch, x, 90);
    x += ctx.measureText(ch).width + 5;
  }

  // text stack, built from the bottom up
  ctx.shadowColor = "rgba(0,0,0,0.5)";
  ctx.shadowBlur = 16;
  let y = bottom;
  const put = (text, font, size, min, maxW, maxLines, ratio, color, gap) => {
    if (!text) return;
    const f = fit(ctx, text, font, maxW, size, min, maxLines);
    const lh = Math.round(f.size * ratio);
    const top = y - f.lines.length * lh;
    ctx.font = font(f.size);
    ctx.fillStyle = color;
    f.lines.forEach((l, i) =>
      ctx.fillText(l, pad, top + i * lh + f.size * 0.92),
    );
    y = top - gap;
  };
  put(footer, (s) => `600 ${s}px ${BODY}`, 32, 22, col, 2, 1.3, c.accent, 18);
  put(
    tagline,
    (s) => `500 ${s}px ${BODY}`,
    34,
    22,
    col,
    2,
    1.3,
    hexA(c.text, 0.92),
    14,
  );
  if (nickname)
    put(
      `“${nickname}”`,
      (s) => `600 ${s}px ${BODY}`,
      46,
      26,
      col,
      1,
      1.25,
      c.accent,
      10,
    );
  put(
    name.toUpperCase(),
    (s) => `400 ${s}px ${HEAD}`,
    story ? 130 : 118,
    50,
    col,
    3,
    0.98,
    c.text,
    22,
  );
  put(
    headline.toUpperCase(),
    (s) => `400 ${s}px ${HEAD}`,
    story ? 190 : 164,
    70,
    w - pad * 2,
    1,
    1,
    c.accent,
    0,
  );
  ctx.shadowBlur = 0;
  ctx.shadowColor = "transparent";

  decorate(ctx, stage, c, w, h, pad, Math.max(y, h * 0.4));

  // QR card
  if (qrDraw) {
    const { qr, n } = qrDraw;
    const cell = Math.floor((qrBox - 40) / n);
    const real = cell * n;
    const cardSize = real + 40;
    const qx = w - pad - cardSize;
    const qy = bottom - cardSize;
    ctx.save();
    ctx.shadowColor = "rgba(0,0,0,0.45)";
    ctx.shadowBlur = 24;
    ctx.fillStyle = "#ffffff";
    roundRect(ctx, qx, qy, cardSize, cardSize, 26);
    ctx.fill();
    ctx.restore();
    ctx.fillStyle = "#000000";
    for (let r = 0; r < n; r++)
      for (let k = 0; k < n; k++)
        if (qr.isDark(r, k))
          ctx.fillRect(qx + 20 + k * cell, qy + 20 + r * cell, cell, cell);
    ctx.fillStyle = hexA(c.text, 0.85);
    const short = String(voteUrl)
      .replace(/^https?:\/\//, "")
      .replace(/^www\./, "");
    const f = fit(
      ctx,
      short,
      (s) => `600 ${s}px ${BODY}`,
      cardSize + 40,
      24,
      14,
      1,
    );
    ctx.font = `600 ${f.size}px ${BODY}`;
    ctx.textAlign = "center";
    ctx.fillText(f.lines[0] ?? short, qx + cardSize / 2, qy + cardSize + 38);
    ctx.textAlign = "left";
  }
  return { stage };
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

/** True when this device can open its share sheet with a picture attached (WhatsApp, Instagram, Facebook...). */
export const canShareImage = () => {
  try {
    return Boolean(
      navigator.canShare?.({
        files: [new File([""], "x.png", { type: "image/png" })],
      }),
    );
  } catch {
    return false;
  }
};

export const shareFlier = (canvas, fileName, text) =>
  new Promise((resolve, reject) => {
    canvas.toBlob(async (blob) => {
      if (!blob) return reject(new Error("Could not create the image."));
      try {
        await navigator.share({
          files: [new File([blob], fileName, { type: "image/png" })],
          text,
        });
        resolve();
      } catch (err) {
        if (err?.name === "AbortError") resolve();
        else reject(err);
      }
    }, "image/png");
  });
