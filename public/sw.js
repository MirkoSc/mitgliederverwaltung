/*
 * Service worker of the installable app (PWA, docs/spec/06-oberflaeche.md
 * section 6). It exists only because a browser offers "install" for a site
 * with a service worker - it does NOTHING else, on purpose:
 *
 * - no Cache API, no IndexedDB, no fetch handler: member data must never be
 *   kept in the browser (CLAUDE.md section 4, docs/spec/02-sicherheit.md
 *   section 7). Every request goes straight to the network exactly as
 *   without a service worker, Cache-Control: no-store included.
 * - no offline page: without the server there is nothing to show.
 *
 * Served from the root (scope /) with Cache-Control: no-cache
 * (App\Http\StaticFileHandler), so a new release replaces it at once.
 */
'use strict';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (ereignis) {
    ereignis.waitUntil(self.clients.claim());
});
