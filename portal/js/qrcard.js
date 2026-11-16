import { loadQr, downloadFlier } from "../../shared/js/components/flier.js";

/** A share card with a big QR code to the nominee's profile, for status posts, stickers and posters. */
export async function drawQrCard(canvas, { name, category, edition, url }) {
  const W = 900;
  const H = 1100;
  canvas.width = W;
  canvas.height = H;
  const ctx = canvas.getContext("2d");
  ctx.fillStyle = "#0a0a0a";
  ctx.fillRect(0, 0, W, H);
  ctx.strokeStyle = "#d4af37";
  ctx.lineWidth = 6;
  ctx.strokeRect(24, 24, W - 48, H - 48);
  ctx.textAlign = "center";
  ctx.fillStyle = "#d4af37";
  ctx.font = '700 30px "DM Sans", Arial, sans-serif';
  if ("letterSpacing" in ctx) ctx.letterSpacing = "8px";
  ctx.fillText("VOTE FOR", W / 2, 110);
  if ("letterSpacing" in ctx) ctx.letterSpacing = "0px";
  ctx.fillStyle = "#f7f5ef";
  let size = 72;
  ctx.font = `700 ${size}px "Playfair Display", Georgia, serif`;
  while (ctx.measureText(name).width > W - 140 && size > 34) {
    size -= 4;
    ctx.font = `700 ${size}px "Playfair Display", Georgia, serif`;
  }
  ctx.fillText(name, W / 2, 200);
  ctx.fillStyle = "#d4af37";
  ctx.font = '600 34px "DM Sans", Arial, sans-serif';
  if (category) ctx.fillText(category, W / 2, 260);
  // the code on a white plate, so any phone camera reads it
  const plate = 600;
  const x = (W - plate) / 2;
  const y = 320;
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(x, y, plate, plate);
  try {
    const qr = (await loadQr())(0, "M");
    qr.addData(url);
    qr.make();
    const n = qr.getModuleCount();
    const cell = Math.max(3, Math.floor((plate - 80) / n)); // whole pixels, so every module stays square and the quiet zone stays clean
    const off = (plate - cell * n) / 2;
    ctx.fillStyle = "#000000";
    for (let r = 0; r < n; r++)
      for (let c = 0; c < n; c++)
        if (qr.isDark(r, c))
          ctx.fillRect(x + off + c * cell, y + off + r * cell, cell, cell);
  } catch {
    ctx.fillStyle = "#333333";
    ctx.font = '600 28px "DM Sans", Arial, sans-serif';
    ctx.fillText(
      "QR code unavailable. Use the link instead.",
      W / 2,
      y + plate / 2,
    );
  }
  ctx.fillStyle = "#f7f5ef";
  ctx.font = '600 34px "DM Sans", Arial, sans-serif';
  ctx.fillText("Scan to open my profile and vote", W / 2, 990);
  ctx.fillStyle = "#b9b6ac";
  ctx.font = '500 28px "DM Sans", Arial, sans-serif';
  ctx.fillText(edition, W / 2, 1040);
  return canvas;
}

export { downloadFlier as downloadCard };
