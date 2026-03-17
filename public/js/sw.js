// Use a cacheName for cache versioning
var cacheName = 'ep3bs_v3.9:static';

// During the installation phase, cache static assets
self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open(cacheName).then(function(cache) {
            return cache.addAll([
                '../',
                '../css/app.css',
                '../vendor/bootstrap/css/bootstrap.min.css',
                '../css/jquery-ui/jquery-ui.min.css',
                '../js/jquery/jquery.min.js',
                '../js/jquery-ui/jquery-ui.min.js',
                '../js/controller/frontend/index.min.js',
                '../js/controller/frontend/hammer.min.js',
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
            ]).then(function() {
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

// When the browser fetches a URL, respond with cache or network
self.addEventListener('fetch', function(event) {
    event.respondWith(
        caches.match(event.request).then(function(response) {
            if (response) {
                return response;
            }
            return fetch(event.request);
        })
    );
});
