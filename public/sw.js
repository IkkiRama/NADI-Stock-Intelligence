const ASSET_CACHE = 'nadi-build-assets-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(ASSET_CACHE).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== ASSET_CACHE).map((key) => caches.delete(key)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Cache-first ONLY for fingerprinted Vite build assets
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches.match(req).then((cached) => {
                if (cached) {
                    return cached;
                }
                return fetch(req).then((res) => {
                    if (res.ok) {
                        const clone = res.clone();
                        caches.open(ASSET_CACHE).then((cache) => cache.put(req, clone));
                    }
                    return res;
                });
            })
        );
        return;
    }

    // Network-only for HTML navigations with offline fallback; never cache API/Inertia JSON
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(OFFLINE_URL))
        );
    }
});
