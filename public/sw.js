const CACHE_NAME = 'llb-crm-v2';
const ASSETS_TO_CACHE = [
    '/manifest.json',
    '/images/logo.png',
    '/images/favicon.png'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    // Ne JAMAIS intercepter les requêtes POST (Livewire), ni les pages HTML
    if (event.request.method !== 'GET') return;
    if (event.request.headers.get('accept')?.includes('text/html')) return;
    if (event.request.url.includes('/livewire')) return;

    // Uniquement cacher les assets statiques (images, manifest)
    event.respondWith(
        caches.match(event.request).then((response) => {
            return response || fetch(event.request);
        })
    );
});
