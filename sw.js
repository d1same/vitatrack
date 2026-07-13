// Service worker: offline shell cache + notification display.
const CACHE = 'vitatrack-v26';
const SHELL = ['./index.php', './assets/app.css?v=26', './assets/app.js?v=26', './assets/fonts/InterVariable.woff2', './icons/icon.svg'];

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
    self.registration.showNotification(d.title || 'Thrive', {
      body: d.body || '',
      icon: './icons/icon-180.png',
      badge: './icons/icon-180.png',
      tag: d.tag || 'vitatrack',
    });
  }
});

// Background push: the server sends an empty push; we fetch what to show.
self.addEventListener('push', e => {
  e.waitUntil(
    fetch('./api.php?action=due_reminder', { method: 'POST', body: '{}', credentials: 'same-origin' })
      .then(r => r.json())
      .catch(() => null)
      .then(d => {
        const n = d && d.title ? d : { title: 'Thrive', body: 'Time for a healthy habit — log your day.' };
        return self.registration.showNotification(n.title, {
          body: n.body,
          icon: './icons/icon-180.png',
          badge: './icons/icon-180.png',
          tag: 'vt-reminder',
        });
      })
  );
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(clients.matchAll({ type: 'window' }).then(list => {
    if (list.length) return list[0].focus();
    return clients.openWindow('./index.php');
  }));
});
