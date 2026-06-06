/* AeroMarkup service worker — offline app shell cache.
   Strategy: cache-first for the app shell so the tool launches with no
   network. API calls (/api/*) always go to network and never block the UI. */
const CACHE = "aeromarkup-v1";
const SHELL = [
  "./",
  "index.html",
  "app.css",
  "app.js",
  "manifest.webmanifest",
  "icon.svg",
];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  // never cache the API — let it fail gracefully offline
  if (url.pathname.includes("/api/")) {
    e.respondWith(fetch(e.request).catch(() => new Response(
      JSON.stringify({ error: "offline" }), { status: 503, headers: { "Content-Type": "application/json" } }
    )));
    return;
  }
  // cache-first for app shell, fall back to network, then cache the result
  e.respondWith(
    caches.match(e.request).then((hit) =>
      hit || fetch(e.request).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copy)).catch(() => {});
        return res;
      }).catch(() => caches.match("index.html"))
    )
  );
});
