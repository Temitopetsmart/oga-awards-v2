
const CACHE_NAME = "oga-public-static-v1";
const APP_ROOT = new URL("./", self.registration.scope);

const APP_SHELL = [
  APP_ROOT.href,
  new URL("assets/images/logo/icon-192.png", APP_ROOT).href,
  new URL("assets/images/logo/icon-512.png", APP_ROOT).href,
  new URL("assets/images/logo/apple-touch-icon.png", APP_ROOT).href
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );

  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key.startsWith("oga-public-static-") && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );

  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const request = event.request;

  if (request.method !== "GET") return;

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) return;

  const excludedPaths = ["api/", "admin/", "portal/", "uploads/"];

  const relativePath = url.pathname.startsWith(APP_ROOT.pathname)
    ? url.pathname.slice(APP_ROOT.pathname.length)
    : "";

  if (excludedPaths.some((path) => relativePath.startsWith(path))) return;

  const isAppHome =
    request.mode === "navigate" &&
    (url.pathname === APP_ROOT.pathname ||
      url.pathname === new URL("index.html", APP_ROOT).pathname);

  if (isAppHome) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) =>
              cache.put(APP_ROOT.href, copy)
            );
          }

          return response;
        })
        .catch(async () => {
          return (
            (await caches.match(APP_ROOT.href)) ||
            Response.error()
          );
        })
    );

    return;
  }

  const isStaticAsset =
    relativePath.startsWith("assets/") ||
    relativePath.startsWith("shared/") ||
    relativePath.startsWith("site/");

  if (!isStaticAsset) return;

  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) =>
            cache.put(request, copy)
          );
        }

        return response;
      })
      .catch(async () => {
        return (await caches.match(request)) || Response.error();
      })
  );
});
