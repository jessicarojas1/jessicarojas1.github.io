/* AeroMarkup service worker — offline app-shell cache.
   Cache-first for the shell (so the tool launches air-gapped); /api/* always
   hits the network and fails soft so the offline-first UI never blocks. */
const CACHE = "aeromarkup-v4";
const SHELL = [
  "./", "index.html", "app.css", "editor.css", "manifest.webmanifest", "icon.svg",
  "js/app.js", "js/router.js", "js/store.js", "js/session.js", "js/audit.js",
  "js/api.js", "js/ui.js", "js/icons.js", "js/canvas.js", "js/snapshot.js",
  "js/charts.js", "js/branding.js", "js/viewer3d.js", "js/views.js",
];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (url.pathname.includes("/api/")) {
    e.respondWith(fetch(e.request).catch(() =>
      new Response(JSON.stringify({ error: "offline" }), { status: 503, headers: { "Content-Type": "application/json" } })));
    return;
  }
  e.respondWith(
    caches.match(e.request).then((hit) =>
      hit || fetch(e.request).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copy)).catch(() => {});
        return res;
      }).catch(() => caches.match("index.html")))
  );
});
