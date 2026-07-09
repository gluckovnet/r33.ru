const CACHE_NAME = 'r33-cache-v1';
const URLS_TO_CACHE = [
  '/',
  '/data/cities.json',
  '/manifest.json'
];
const LEGAL_PREFIX = '/static/legal/';
const ICON_PREFIX = '/icons/';
const CRM_ORIGIN = 'https://lk.r33.ru';

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(URLS_TO_CACHE);
    })
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      );
    })
  );
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  const path = url.pathname;
  const origin = url.origin;

  // CRM — network only
  if (origin === CRM_ORIGIN) {
    return;
  }

  // Icons — cache first
  if (path.startsWith(ICON_PREFIX)) {
    event.respondWith(
      caches.match(event.request).then(cached => cached || fetchAndCache(event.request))
    );
    return;
  }

  // Legal docs — network first
  if (path.startsWith(LEGAL_PREFIX)) {
    event.respondWith(networkFirst(event.request));
    return;
  }

  // Main page + cities data — network first, cache fallback
  if (path === '/' || path === '/data/cities.json') {
    event.respondWith(networkFirst(event.request));
    return;
  }

  // Everything else — network only
});

function fetchAndCache(request) {
  return fetch(request).then(response => {
    if (!response || response.status !== 200) return response;
    const clone = response.clone();
    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
    return response;
  });
}

function networkFirst(request) {
  return fetch(request).then(response => {
    if (!response || response.status !== 200) return response;
    const clone = response.clone();
    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
    return response;
  }).catch(() => {
    return caches.match(request).then(cached => {
      if (cached) return cached;
      return new Response(
        '<html><body style="background:#0a0a0f;color:#e4e4ef;display:flex;align-items:center;justify-content:center;min-height:100vh"><p>Контент недоступен офлайн</p></body></html>',
        { headers: { 'Content-Type': 'text/html' } }
      );
    });
  });
}

self.addEventListener('message', event => {
  if (event.data === 'skipWaiting') {
    self.skipWaiting();
  }
});
