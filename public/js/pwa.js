/*
 * Registers the service worker /sw.js, which makes the application
 * installable as an app (PWA, docs/spec/06-oberflaeche.md section 6). The
 * worker caches nothing - see the comment in public/sw.js. Browsers allow
 * service workers only in a secure context (HTTPS, or localhost while
 * developing); elsewhere this does nothing.
 */
(function () {
    'use strict';

    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    // updateViaCache "none": the browser always asks the server for a new
    // worker script instead of its HTTP cache.
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(function () {
        // Not installable then - the application works the same.
    });
}());
