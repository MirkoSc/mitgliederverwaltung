/*
 * Several fees at once (docs/spec/08-beitraege.md sections 2a and 3, #90,
 * #133): "Alle auswählen", the counter and Shift for a range - a click (or
 * Shift+Space) with Shift held gives every checkbox between the one clicked
 * last and this one the state of this one; all others stay as they are.
 * Optionally a search field that hides the rows that do not match (name and
 * member number; every word must match, like the member search) and the sum
 * of the chosen amounts.
 *
 * A page may hold several groups: an element [data-mehrfach-auswahl="x"]
 * holds the head, its checkboxes carry [data-mehrfach-haken="x"] (they may
 * stand outside it and belong to the form through form=…). "Alle
 * auswählen" and the range work on the rows the search shows; a hidden row
 * keeps its tick. Nothing is stored in the browser.
 *
 * Without this script the checkboxes are a plain form; all this is extra.
 * The logic lives in plain functions, tested with node --test
 * (tests/js/beitraege-auswahl.test.js).
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

    /**
     * Lower case, without accents, ß as ss - so "muller" finds "Müller".
     *
     * @param {string} text
     */
    function normal(text) {
        return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ß/g, 'ss');
    }

    /**
     * Whether a row matches the search: every word of `eingabe` occurs in
     * `suchtext`, in any order. An empty search matches everything.
     *
     * @param {string} suchtext
     * @param {string} eingabe
     */
    function passt(suchtext, eingabe) {
        var text = normal(suchtext);
        return normal(eingabe).split(/\s+/).filter(Boolean).every(function (wort) {
            return text.indexOf(wort) !== -1;
        });
    }

    /**
     * Integer cent as the page shows money: "1.234,56 €".
     *
     * @param {number} cent
     */
    function geld(cent) {
        var vorzeichen = cent < 0 ? '-' : '';
        var betrag = Math.abs(Math.round(cent));
        var euro = String(Math.floor(betrag / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        var rest = String(betrag % 100).padStart(2, '0');
        return vorzeichen + euro + ',' + rest + ' €';
    }

    /**
     * The counter with the total and the sum: "3 von 10 ausgewählt, 85,00 €".
     *
     * @param {number} n
     * @param {number} gesamt
     * @param {number} cent
     */
    function summenText(n, gesamt, cent) {
        return n + ' von ' + gesamt + ' ausgewählt, ' + geld(cent);
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = {
            bereich: bereich,
            anzahl: anzahl,
            zaehlerText: zaehlerText,
            alleZustand: alleZustand,
            passt: passt,
            geld: geld,
            summenText: summenText,
        };
    }

    if (typeof document === 'undefined') {
        return;
    }

    var umschalt = false;

    /** @param {Element} formular */
    function gruppeEinrichten(formular) {
        var gruppe = formular.getAttribute('data-mehrfach-auswahl') || '';
        var haken = Array.prototype.slice.call(document.querySelectorAll('input[data-mehrfach-haken]')).filter(function (h) {
            return (h.getAttribute('data-mehrfach-haken') || '') === gruppe;
        });
        var kopf = formular.querySelector('[data-mehrfach-kopf]');
        var alle = formular.querySelector('[data-mehrfach-alle]');
        var zaehler = formular.querySelector('[data-mehrfach-zaehler]');
        var suche = formular.querySelector('[data-mehrfach-suche]');
        var nurLeer = formular.querySelector('[data-mehrfach-nur-leer]');
        if (haken.length === 0 || kopf === null || alle === null || zaehler === null) {
            return;
        }
        var mitSumme = haken.every(function (h) {
            return h.hasAttribute('data-cent');
        });
        var letzter = null;

        function zeile(h) {
            return h.closest('tr');
        }

        function sichtbar() {
            return haken.filter(function (h) {
                var z = zeile(h);
                return z === null || !z.hidden;
            });
        }

        function zustaende(liste) {
            return liste.map(function (h) {
                return h.checked;
            });
        }

        function anzeigen() {
            var stand = alleZustand(zustaende(sichtbar()));
            alle.checked = stand === 'alle';
            alle.indeterminate = stand === 'teils';
            var gewaehlt = haken.filter(function (h) {
                return h.checked;
            });
            zaehler.textContent = mitSumme
                ? summenText(gewaehlt.length, haken.length, gewaehlt.reduce(function (summe, h) {
                    return summe + Number(h.getAttribute('data-cent'));
                }, 0))
                : zaehlerText(gewaehlt.length);
        }

        function filtern() {
            var eingabe = suche === null ? '' : suche.value;
            var nurOhne = nurLeer !== null && nurLeer.checked;
            haken.forEach(function (h) {
                var z = zeile(h);
                if (z !== null) {
                    z.hidden = !passt(z.getAttribute('data-suche') || '', eingabe) || (nurOhne && h.checked);
                }
            });
            letzter = null;
            anzeigen();
        }

        haken.forEach(function (h) {
            h.addEventListener('click', function (ereignis) {
                var liste = sichtbar();
                var index = liste.indexOf(h);
                var neu = bereich(zustaende(liste), letzter, index, h.checked, ereignis.shiftKey || umschalt);
                neu.forEach(function (wert, i) {
                    liste[i].checked = wert;
                });
                letzter = index;
                if (nurLeer !== null && nurLeer.checked) {
                    // A ticked row leaves the view "Nur nicht ausgewählte".
                    filtern();
                    return;
                }
                anzeigen();
            });
        });

        alle.addEventListener('change', function () {
            sichtbar().forEach(function (h) {
                h.checked = alle.checked;
            });
            letzter = null;
            anzeigen();
        });

        if (suche !== null) {
            suche.addEventListener('input', filtern);
            // Enter in the search must not send the form.
            suche.addEventListener('keydown', function (ereignis) {
                if (ereignis.key === 'Enter') {
                    ereignis.preventDefault();
                }
            });
        }
        if (nurLeer !== null) {
            nurLeer.addEventListener('change', filtern);
        }

        kopf.hidden = false;
        filtern();
    }

    function einrichten() {
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
        Array.prototype.forEach.call(document.querySelectorAll('[data-mehrfach-auswahl]'), gruppeEinrichten);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', einrichten);
    } else {
        einrichten();
    }
})();
