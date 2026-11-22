import "./pwa-install.js";
const manifestUrl = new URL("../../manifest.webmanifest", import.meta.url);
const serviceWorkerUrl = new URL("../../sw.js", import.meta.url);
const appScope = new URL("../../", import.meta.url);

const manifestLink = document.createElement("link");
manifestLink.rel = "manifest";
manifestLink.href = manifestUrl.href;
document.head.appendChild(manifestLink);

if ("serviceWorker" in navigator && window.isSecureContext) {
  window.addEventListener("load", () => {
    navigator.serviceWorker
      .register(serviceWorkerUrl.href, {
        scope: appScope.pathname,
      })
      .catch((error) => {
        console.error("OGA service worker registration failed:", error);
      });
  });
}
