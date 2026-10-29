/*
 * Idle warning (docs/spec/06-oberflaeche.md section 4): shortly before the
 * session runs out a dialog offers "Weiterarbeiten". The limit comes from
 * data-leerlauf (seconds) and the keep-alive route from data-weiter on the
 * <body>; the CSRF token is the one htmx already carries in hx-headers.
 * The timing decision lives in phase() - plain logic, tested with
 * node --test (tests/js/leerlauf.test.js).
 */
(function () {
    'use strict';

    var VORWARNUNG = 5 * 60;

    /**
     * @param {number} vergangen seconds since the last request
     * @param {number} limit idle limit in seconds
     * @returns {'ruhig'|'warnung'|'abgelaufen'}
     */
    function phase(vergangen, limit) {
        if (vergangen >= limit) {
            return 'abgelaufen';
        }
        return vergangen >= limit - Math.min(VORWARNUNG, limit / 2) ? 'warnung' : 'ruhig';
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { phase: phase };
    }

    if (typeof document === 'undefined') {
        return;
    }

    var body = document.body;
    var limit = parseInt(body.getAttribute('data-leerlauf') || '0', 10);
    var url = body.getAttribute('data-weiter');
    var dialog = document.getElementById('leerlauf-warnung');
    if (!limit || !url || !dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var letzte = Date.now();
    var knopf = dialog.querySelector('[data-weiterarbeiten]');
    var zeit = dialog.querySelector('[data-rest]');

    function token() {
        try {
            return JSON.parse(body.getAttribute('hx-headers') || '{}')['X-CSRF-Token'] || '';
        } catch (e) {
            return '';
        }
    }

    function weiter() {
        fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': token() }, credentials: 'same-origin' })
            .then(function (antwort) {
                if (antwort.ok) {
                    letzte = Date.now();
                    dialog.close();
                } else {
                    window.location.reload();
                }
            })
            .catch(function () { /* the next tick tries to show the state again */ });
    }

    function takt() {
        var vergangen = (Date.now() - letzte) / 1000;
        var p = phase(vergangen, limit);
        if (p === 'abgelaufen') {
            window.location.reload();
            return;
        }
        if (p === 'warnung' && !dialog.open) {
            dialog.showModal();
            if (knopf) {
                knopf.focus();
            }
        }
        if (dialog.open && zeit) {
            zeit.textContent = String(Math.max(0, Math.ceil((limit - vergangen) / 60)));
        }
    }

    if (knopf) {
        knopf.addEventListener('click', weiter);
    }
    // Any htmx request reaches the server and so renews the session there.
    document.addEventListener('htmx:afterRequest', function () {
        if (!dialog.open) {
            letzte = Date.now();
        }
    });
    window.setInterval(takt, 15000);
}());
