const stylesheet = document.createElement("link");
stylesheet.rel = "stylesheet";
stylesheet.href = new URL("../css/pwa.css", import.meta.url).href;
document.head.appendChild(stylesheet);

const DISMISS_KEY = "oga-pwa-install-dismissed";
const DISMISS_DAYS = 7;

let deferredPrompt = null;
let installCard = null;

const isStandalone = () =>
  window.matchMedia("(display-mode: standalone)").matches ||
  navigator.standalone === true;

const isIOS = () =>
  /iphone|ipad|ipod/i.test(navigator.userAgent) ||
  (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);

function wasDismissedRecently() {
  try {
    const dismissedAt = Number(localStorage.getItem(DISMISS_KEY));

    if (!dismissedAt) return false;

    const expired =
      Date.now() - dismissedAt > DISMISS_DAYS * 24 * 60 * 60 * 1000;

    if (expired) localStorage.removeItem(DISMISS_KEY);

    return !expired;
  } catch {
    return false;
  }
}

function dismissCard() {
  try {
    localStorage.setItem(DISMISS_KEY, String(Date.now()));
  } catch {
    // The prompt can still be dismissed for this page view.
  }

  installCard?.remove();
  installCard = null;
}

function hideCard() {
  installCard?.remove();
  installCard = null;
}

function createCard(ios = false) {
  if (installCard || isStandalone() || wasDismissedRecently()) return;

  const card = document.createElement("section");
  card.className = "oga-install-card";
  card.setAttribute("role", "region");
  card.setAttribute("aria-label", "Install OGA Awards");

  const logo = document.createElement("img");
  logo.src = new URL(
    "../../assets/images/logo/icon-192.png",
    import.meta.url,
  ).href;
  logo.alt = "OGA Awards";
  logo.width = 48;
  logo.height = 48;

  const content = document.createElement("div");
  content.className = "oga-install-content";

  const heading = document.createElement("strong");
  heading.textContent = "Get OGA Awards on your device";

  const description = document.createElement("p");
  description.textContent = ios
    ? "Install OGA Awards from Safari: tap Share, then Add to Home Screen."
    : "Install OGA Awards for quick access from your home screen.";

  content.append(heading, description);

  const actions = document.createElement("div");
  actions.className = "oga-install-actions";

  if (!ios) {
    const installButton = document.createElement("button");
    installButton.type = "button";
    installButton.className = "oga-install-button";
    installButton.textContent = "Install";

    installButton.addEventListener("click", async () => {
      if (!deferredPrompt) return;

      deferredPrompt.prompt();
      const result = await deferredPrompt.userChoice;

      deferredPrompt = null;

      if (result.outcome === "accepted") {
        hideCard();
      } else {
        dismissCard();
      }
    });

    actions.append(installButton);
  }

  const closeButton = document.createElement("button");
  closeButton.type = "button";
  closeButton.className = "oga-install-close";
  closeButton.setAttribute("aria-label", "Dismiss installation suggestion");
  closeButton.textContent = "Not now";
  closeButton.addEventListener("click", dismissCard);

  actions.append(closeButton);
  card.append(logo, content, actions);

  document.body.append(card);
  installCard = card;
}

if (!isStandalone() && !wasDismissedRecently()) {
  if (isIOS()) {
    window.addEventListener("load", () => {
      window.setTimeout(() => createCard(true), 4000);
    });
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferredPrompt = event;

    window.setTimeout(() => createCard(false), 2500);
  });

  window.addEventListener("appinstalled", () => {
    deferredPrompt = null;
    hideCard();
  });
}
