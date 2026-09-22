// "i want to be able to install the application like a standalone" —
// PHASE9_AUDIT_AND_PLAN.md §20.14. This is a genuine static file (not a
// Laravel route) because a service worker's own scope rules require it
// to be served from a fixed, same-origin URL — see the registration
// call in resources/views/layouts/app.blade.php.
//
// Deliberately a pass-through no-op, on purpose, not an oversight: this
// is a live, transactional hotel system (room availability, folio
// balances, payment status) where a cached "offline" copy of a page
// could show a receptionist a stale room as free, or a stale balance as
// paid, days after either changed. A service worker with a fetch
// handler is one of the browser's installability requirements, so this
// file exists to satisfy that — but it never calls caches.open(),
// never intercepts a request to serve a cached response, and never
// stores anything. Every request still goes to the network exactly as
// if no service worker were registered at all.
self.addEventListener('install', function (event) {
    // Activate this version immediately rather than waiting for every
    // open tab to close first — there is no old cache to keep serving
    // stale content from while the update settles, so there's nothing
    // to lose by switching over right away.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    // Intentionally does nothing — no event.respondWith(), so the
    // browser's normal network fetch proceeds untouched. Registered
    // purely so the "Install app" prompt is offered at all.
});
