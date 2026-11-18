/* =====================================================================
   G-SMaRT HRIS service worker (v36, installable app).
   - Pages always come from the server (never cached: they hold personal and payroll data); when the phone is
     offline the app shows offline.html instead of the browser's error page.
   - The stylesheet, scripts, fonts and icons under assets/ are cached and refreshed in the background, so the
     app opens quickly. Form posts, downloads, uploads and every index.php request go straight to the server.
   ===================================================================== */
var CACHE = 'gsmart-hris-v1';
var PRECACHE = ['offline.html', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icons/favicon-32.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('gsmart-hris-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // pages: the network, or the offline page
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () {
      return caches.match('offline.html');
    }));
    return;
  }
  // static files: from the cache at once, refreshed in the background
  if (url.pathname.indexOf('/assets/') !== -1 && !/\/assets\/manual\//.test(url.pathname)) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) {
          if (res && res.ok && res.type === 'basic') c.put(req, res.clone());
          return res;
        }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
