import { html, mount } from "../../../shared/js/core/dom.js";
import { formatDate } from "../../../shared/js/core/format.js";
import { store } from "../../../shared/js/core/store.js";
import { logAudit } from "../services/admin-store.js";
import { editionOf } from "../services/scope.js";

export const title = "Gate scanner";
export const perm = "scanner.use";
export const crumbs = ["Events", "Gate scanner"];

const RESULT = {
  idle: {
    cls: "",
    icon: "fa-qrcode",
    head: "Ready to scan",
    sub: "Scan a ticket or type its code below.",
  },
  valid: {
    cls: "oga-scan--valid",
    icon: "fa-circle-check",
    head: "Valid — let them in",
  },
  used: {
    cls: "oga-scan--used",
    icon: "fa-triangle-exclamation",
    head: "Already used",
  },
  bad: {
    cls: "oga-scan--bad",
    icon: "fa-circle-xmark",
    head: "Invalid ticket",
  },
};

export default async function scanner() {
  return html` <div class="row g-3">
    <div class="col-lg-7">
      <div id="scanResult"></div>
      <form id="scanForm" class="d-flex gap-2 mt-3" novalidate>
        <input
          class="form-control form-control-lg"
          id="scanInput"
          name="code"
          placeholder="Ticket code, e.g. TKT-ABCD2345"
          autocomplete="off"
          autocapitalize="characters"
          aria-label="Ticket code"
          autofocus
        />
        <button class="btn btn-gold btn-lg" type="submit">Check</button>
      </form>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
        <button class="btn btn-ghost btn-sm" id="camBtn" type="button">
          <i class="fa-solid fa-camera me-1"></i>Use camera
        </button>
        <small class="text-secondary" id="camNote"
          >A USB or Bluetooth scanner works too — it types the code into the
          box.</small
        >
      </div>
      <div id="camBox" class="mt-3 d-none">
        <video id="camVideo" class="oga-video" playsinline muted></video>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="oga-stat mb-3">
        <span class="oga-icon"
          ><i class="fa-solid fa-person-walking-arrow-right"></i
        ></span>
        <span
          ><span class="oga-stat__value" id="inCount">0</span
          ><span class="oga-stat__label">Checked in</span
          ><span class="oga-stat__sub" id="inOf"></span
        ></span>
      </div>
      <div class="card">
        <div class="card-header">
          <h2 class="card-title mb-0">Recent scans</h2>
        </div>
        <div class="card-body"><ul class="oga-feed" id="recent"></ul></div>
      </div>
    </div>
  </div>`;
}

export function init(root, { admin, edition }) {
  const input = root.querySelector("#scanInput");
  const show = (kind, detail = "") => {
    const r = RESULT[kind];
    mount(
      root.querySelector("#scanResult"),
      html`<div class="oga-scan ${r.cls}" role="status" aria-live="assertive">
        <i class="fa-solid ${r.icon}" aria-hidden="true"></i>
        <h2>${r.head}</h2>
        <p class="mb-0 mt-2">${detail || r.sub || ""}</p>
      </div>`,
    );
  };
  const counts = () => {
    const all = store
      .get("tickets", [])
      .filter((t) => editionOf(t) === edition.id);
    root.querySelector("#inCount").textContent = all.filter(
      (t) => t.used,
    ).length;
    root.querySelector("#inOf").textContent = `of ${all.length} issued`;
    const scans = store.get("admin:scans", []).slice(0, 8);
    mount(
      root.querySelector("#recent"),
      scans.length
        ? html`${scans.map(
            (s) =>
              html`<li>
                <span class="flex-grow-1"
                  ><strong>${s.code}</strong
                  ><time
                    >${s.holder ?? ""} ·
                    ${formatDate(s.at, {
                      timeStyle: "short",
                      dateStyle: "medium",
                    })}</time
                  ></span
                >
                <span
                  class="oga-status oga-status--${s.result === "valid"
                    ? "success"
                    : s.result === "used"
                      ? "warning"
                      : "danger"}"
                  >${s.result === "valid"
                    ? "Valid"
                    : s.result === "used"
                      ? "Used"
                      : "Invalid"}</span
                >
              </li>`,
          )}`
        : html`<li class="text-secondary">No scans yet.</li>`,
    );
  };

  function verify(raw) {
    const code = String(raw).trim().toUpperCase();
    if (!code) return;
    const tickets = store.get("tickets", []);
    const t = tickets.find((x) => x.code === code);
    let result = "bad";
    let detail = `No ticket matches “${code}”.`;
    if (t && editionOf(t) !== edition.id) {
      result = "bad";
      detail = `This ticket is for a different edition, not ${edition.name} ${edition.edition}.`;
    } else if (t && !t.used) {
      t.used = true;
      t.usedAt = new Date().toISOString();
      t.usedBy = admin.name;
      store.set("tickets", tickets);
      result = "valid";
      detail = `${t.holder} · ${t.typeName}`;
    } else if (t) {
      result = "used";
      detail = `${t.holder} · scanned ${formatDate(t.usedAt, {
        dateStyle: "medium",
        timeStyle: "short",
      })}`;
    }
    store.set(
      "admin:scans",
      [
        { code, result, holder: t?.holder, at: new Date().toISOString() },
        ...store.get("admin:scans", []),
      ].slice(0, 50),
    );
    if (result === "valid") logAudit(admin, "Checked in ticket", code);
    show(result, detail);
    counts();
  }

  root.querySelector("#scanForm").addEventListener("submit", (e) => {
    e.preventDefault();
    verify(input.value);
    input.value = "";
    input.focus();
  });

  /* Camera scanning (needs a browser with BarcodeDetector, e.g. Chrome/Edge on Android or desktop). */
  const camBtn = root.querySelector("#camBtn");
  let stream = null;
  let timer = null;
  let last = { code: "", at: 0 };
  const stop = () => {
    clearInterval(timer);
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    root.querySelector("#camBox").classList.add("d-none");
    camBtn.innerHTML = '<i class="fa-solid fa-camera me-1"></i>Use camera';
  };
  if (!("BarcodeDetector" in window)) {
    camBtn.disabled = true;
    root.querySelector("#camNote").textContent =
      "Camera scanning isn’t supported in this browser — type the code, or use a USB/Bluetooth scanner.";
  }
  camBtn.addEventListener("click", async () => {
    if (stream) {
      stop();
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: "environment" },
      });
      const video = root.querySelector("#camVideo");
      video.srcObject = stream;
      await video.play();
      root.querySelector("#camBox").classList.remove("d-none");
      camBtn.innerHTML = '<i class="fa-solid fa-stop me-1"></i>Stop camera';
      const detector = new window.BarcodeDetector({ formats: ["qr_code"] });
      timer = setInterval(async () => {
        try {
          const [hit] = await detector.detect(video);
          if (
            hit &&
            (hit.rawValue !== last.code || Date.now() - last.at > 3000)
          ) {
            last = { code: hit.rawValue, at: Date.now() };
            verify(hit.rawValue);
          }
        } catch {
          /* frame not ready */
        }
      }, 350);
    } catch (err) {
      console.error(err);
      stop();
      show(
        "bad",
        "Couldn’t open the camera. Check the browser’s camera permission.",
      );
    }
  });
  window.addEventListener("pagehide", stop);

  show("idle");
  counts();
  input.focus();
}
