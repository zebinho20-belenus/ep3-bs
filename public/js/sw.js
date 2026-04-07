// Use a cacheName for cache versioning
var cacheName = 'ep3bs_v3.22:static';

// Static assets to pre-cache during installation
var staticAssets = [
    '../css/app.css',
    '../vendor/bootstrap/css/bootstrap.min.css',
    '../css/jquery-ui/jquery-ui.min.css',
    '../js/jquery/jquery.min.js',
    '../js/jquery-ui/jquery-ui.min.js',
    '../js/controller/frontend/index.min.js',
    '../js/controller/frontend/hammer.min.js',
    '../js/controller/calendar/index.min.js',
    '../js/default.min.js',
    '../js/jquery-ui/i18n/de-DE.js',
    '../imgs/icons/locale/en-US.png',
    '../imgs/icons/locale/de-DE.png',
    '../imgs/icons/wait.gif',
    '../imgs/icons/plus-link.png',
    '../imgs/icons/calendar.png',
    '../imgs/icons/user.png',
    '../imgs/icons/off.png',
    '../imgs/icons/plus.png',
    '../imgs/icons/warning.png',
    '../imgs/icons/tag.png',
    '../imgs/icons/attachment.png',
    '../imgs-client/layout/logo.png',
    '../imgs-client/icons/fav.ico'
];

// During the installation phase, cache static assets
self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open(cacheName).then(function(cache) {
            return cache.addAll(staticAssets).then(function() {
                self.skipWaiting();
            });
        })
    );
});

// Clean up old caches on activation
self.addEventListener('activate', function(e) {
    e.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) {
                    return name !== cacheName;
                }).map(function(name) {
                    return caches.delete(name);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// Fetch strategy: network-first for navigation (HTML), cache-first for static assets
self.addEventListener('fetch', function(event) {
    // Only handle GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Navigation requests (HTML pages): network-first with cache fallback
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(function() {
                return caches.match(event.request).then(function(response) {
                    return response || caches.match('../');
                });
            })
        );
        return;
    }

    // Static assets (CSS, JS, images, fonts): cache-first with network fallback
    event.respondWith(
        caches.match(event.request).then(function(response) {
            if (response) {
                return response;
            }
            return fetch(event.request).then(function(networkResponse) {
                // Cache new static assets for future use
                var url = event.request.url;
                if (url.match(/\.(css|js|png|jpg|jpeg|gif|ico|woff2?|svg)(\?.*)?$/)) {
                    var responseClone = networkResponse.clone();
                    caches.open(cacheName).then(function(cache) {
                        cache.put(event.request, responseClone);
                    });
                }
                return networkResponse;
            });
        })
    );
});
