/*
 * Several fees at once on the page "Beiträge" (app/views/beitraege/seite.php,
 * docs/spec/08-beitraege.md section 2a, #90): "Alle auswählen", the counter
 * "n ausgewählt" and Shift for a range - a click (or Shift+Space) with Shift
 * held gives every checkbox between the one clicked last and this one the
 * state of this one; all others stay as they are.
 *
 * Without this script the checkboxes are a plain form; the range is an
 * extra. The range logic lives in bereich() - plain, tested with
 * node --test (tests/js/beitraege-auswahl.test.js).
 */
(function () {
    'use strict';

    /**
     * The states after a click on box `jetzt`, whose new state is `wert`.
     * With Shift and an earlier click the whole range from `letzter` to
     * `jetzt` (both included, either direction) takes `wert`; otherwise only
     * `jetzt`. Returns a new array, the input stays untouched.
     *
     * @param {boolean[]} zustaende
     * @param {number|null} letzter index of the box clicked before, or null
     * @param {number} jetzt index of the box clicked now
     * @param {boolean} wert its new state
     * @param {boolean} umschalt Shift held
     * @returns {boolean[]}
     */
    function bereich(zustaende, letzter, jetzt, wert, umschalt) {
        var neu = zustaende.slice();
        if (jetzt < 0 || jetzt >= neu.length) {
            return neu;
        }
        var gueltig = typeof letzter === 'number' && letzter >= 0 && letzter < neu.length;
        var von = umschalt && gueltig ? Math.min(letzter, jetzt) : jetzt;
        var bis = umschalt && gueltig ? Math.max(letzter, jetzt) : jetzt;
        for (var i = von; i <= bis; i++) {
            neu[i] = wert;
        }
        return neu;
    }

    /** @param {boolean[]} zustaende */
    function anzahl(zustaende) {
        return zustaende.filter(Boolean).length;
    }

    /** @param {number} n */
    function zaehlerText(n) {
        return n + ' ausgewählt';
    }

    /**
     * What "Alle auswählen" shows: ticked when all are, half when some are.
     *
     * @param {boolean[]} zustaende
     * @returns {'alle'|'teils'|'keine'}
     */
    function alleZustand(zustaende) {
        var n = anzahl(zustaende);
        if (n === 0) {
            return 'keine';
        }
        return n === zustaende.length ? 'alle' : 'teils';
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { bereich: bereich, anzahl: anzahl, zaehlerText: zaehlerText, alleZustand: alleZustand };
    }

    if (typeof document === 'undefined') {
        return;
    }

    function einrichten() {
        var formular = document.querySelector('[data-mehrfach-auswahl]');
        if (formular === null) {
            return;
        }
        var haken = Array.prototype.slice.call(document.querySelectorAll('input[data-mehrfach-haken]'));
        var kopf = formular.querySelector('[data-mehrfach-kopf]');
        var alle = formular.querySelector('[data-mehrfach-alle]');
        var zaehler = formular.querySelector('[data-mehrfach-zaehler]');
        if (haken.length === 0 || kopf === null || alle === null || zaehler === null) {
            return;
        }

        var letzter = null;
        var umschalt = false;

        function zustaende() {
            return haken.map(function (h) {
                return h.checked;
            });
        }

        function anzeigen() {
            var z = zustaende();
            var stand = alleZustand(z);
            alle.checked = stand === 'alle';
            alle.indeterminate = stand === 'teils';
            zaehler.textContent = zaehlerText(anzahl(z));
        }

        // Shift+Space: not every browser puts shiftKey on the click a key
        // makes, so the key itself is remembered as well.
        document.addEventListener('keydown', function (ereignis) {
            if (ereignis.key === 'Shift') {
                umschalt = true;
            }
        });
        document.addEventListener('keyup', function (ereignis) {
            if (ereignis.key === 'Shift') {
                umschalt = false;
            }
        });
        window.addEventListener('blur', function () {
            umschalt = false;
        });

        haken.forEach(function (h, index) {
            h.addEventListener('click', function (ereignis) {
                var neu = bereich(zustaende(), letzter, index, h.checked, ereignis.shiftKey || umschalt);
                neu.forEach(function (wert, i) {
                    haken[i].checked = wert;
                });
                letzter = index;
                anzeigen();
            });
        });

        alle.addEventListener('change', function () {
            haken.forEach(function (h) {
                h.checked = alle.checked;
            });
            letzter = null;
            anzeigen();
        });

        kopf.hidden = false;
        anzeigen();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', einrichten);
    } else {
        einrichten();
    }
})();
