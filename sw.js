// Service worker: offline shell cache + notification display.
const CACHE = 'vitatrack-v11';
const SHELL = ['./index.php', './assets/app.css?v=11', './assets/app.js?v=11', './assets/fonts/InterVariable.woff2', './icons/icon.svg'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.pathname.includes('api.php')) return; // network-only for API
  e.respondWith(
    fetch(e.request)
      .then(resp => {
        const copy = resp.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy));
        return resp;
      })
      .catch(() => caches.match(e.request))
  );
});

// Show a notification when the page posts a message (used by the in-app reminder scheduler)
self.addEventListener('message', e => {
  const d = e.data || {};
  if (d.type === 'notify') {
    self.registration.showNotification(d.title || 'VitaTrack', {
      body: d.body || '',
      icon: './icons/icon-180.php',
      badge: './icons/icon-180.php',
      tag: d.tag || 'vitatrack',
    });
  }
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(clients.matchAll({ type: 'window' }).then(list => {
    if (list.length) return list[0].focus();
    return clients.openWindow('./index.php');
  }));
});
