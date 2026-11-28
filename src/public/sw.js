/* Lucas · Service Worker (Fase 16 · PWA instalable)
 *
 * Seguridad financiera (RNF-04, Cache-Control: no-store):
 *  - SOLO almacena en caché activos estáticos públicos (CSS, JS, iconos, favicon y offline.html).
 *  - NUNCA almacena en caché páginas HTML autenticadas (.php), respuestas de formularios ni exportaciones CSV.
 *  - Si una navegación GET falla por falta de red, muestra la pantalla estática /offline.html.
 */
'use strict';

var CACHE_NAME = 'lucas-static-v4';
var PRECACHE_ASSETS = [
  '/offline.html',
  '/favicon.svg',
  '/manifest.webmanifest',
  '/icons/apple-touch-icon.png',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable-512.png',
  '/css/tokens.css',
  '/css/base.css',
  '/css/components.css',
  '/css/layout.css',
  '/css/pages.css',
  '/js/app.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(PRECACHE_ASSETS);
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE_NAME; })
            .map(function (k) { return caches.delete(k); })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function isStaticAsset(url) {
  if (url.origin !== self.location.origin) return false;
  return /^\/(css|js|icons)\//.test(url.pathname) ||
         url.pathname === '/favicon.svg' ||
         url.pathname === '/manifest.webmanifest' ||
         url.pathname === '/offline.html';
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url = new URL(req.url);

  // 1. Navegación a páginas de la aplicación: SIEMPRE desde la red (nunca cachear HTML con datos de sesión).
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () {
        return caches.match('/offline.html');
      })
    );
    return;
  }

  // 2. Activos estáticos públicos: Stale-While-Revalidate.
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.open(CACHE_NAME).then(function (cache) {
        return cache.match(req).then(function (cached) {
          var fetched = fetch(req).then(function (networkRes) {
            if (networkRes && networkRes.status === 200) {
              cache.put(req, networkRes.clone());
            }
            return networkRes;
          }).catch(function () {
            return cached;
          });
          return cached || fetched;
        });
      })
    );
  }
});
