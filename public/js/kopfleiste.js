/*
 * Header (app/views/partials/kopf.php, docs/spec/06-oberflaeche.md
 * section 2): it sticks to the top. On a phone it slides away while the
 * page is scrolled down and comes back with the least scrolling up - the
 * screen is small, the header is not. On a PC, in the Barrierefrei-Modus and
 * with reduced motion it always stays (the CSS repeats that rule, so the
 * class alone can never hide it there).
 *
 * The script also measures the header into the CSS variable --kopf-hoehe
 * (through the CSSOM, which style-src 'self' allows): sticky parts of a
 * page and scroll targets keep clear of it.
 *
 * The decision lives in sollVerstecken() - plain logic, tested with
 * node --test 'tests/js/**' (see tests/js/kopfleiste.test.js).
 */
(function () {
    'use strict';

    /**
     * @param {{y: number, vorher: number, versteckt: boolean, schwelle: number,
     *          schmal: boolean, ruhig: boolean, menueOffen: boolean, fokusImKopf: boolean}} lage
     * @returns {boolean} whether the header is to be hidden now
     */
    function sollVerstecken(lage) {
        if (!lage.schmal || lage.ruhig || lage.menueOffen || lage.fokusImKopf) {
            return false;
        }
        // Near the top the header is simply part of the page.
        if (lage.y <= lage.schwelle) {
            return false;
        }
        if (lage.y < lage.vorher) {
            return false;
        }
        if (lage.y > lage.vorher) {
            return true;
        }

        return lage.versteckt;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { sollVerstecken: sollVerstecken };
    }

    if (typeof document === 'undefined') {
        return;
    }

    var kopf = document.querySelector('.kopf');
    if (!kopf) {
        return;
    }
    var wurzel = document.documentElement;
    var schmal = window.matchMedia('(max-width: 47.99rem)');
    var ruhig = window.matchMedia('(prefers-reduced-motion: reduce)');
    var vorher = window.scrollY;
    var versteckt = false;
    var geplant = false;

    function messen() {
        wurzel.style.setProperty('--kopf-hoehe', kopf.offsetHeight + 'px');
    }

    function anwenden() {
        geplant = false;
        // Clamped: the rubber band at either end of the page on a phone
        // must not count as scrolling.
        var ende = Math.max(0, wurzel.scrollHeight - window.innerHeight);
        var y = Math.min(Math.max(0, window.scrollY), ende);
        var neu = sollVerstecken({
            y: y,
            vorher: vorher,
            versteckt: versteckt,
            schwelle: kopf.offsetHeight,
            schmal: schmal.matches,
            ruhig: ruhig.matches || wurzel.classList.contains('modus-barrierefrei'),
            menueOffen: kopf.querySelector('details[open]') !== null,
            fokusImKopf: kopf.contains(document.activeElement),
        });
        vorher = y;
        if (neu !== versteckt) {
            versteckt = neu;
            wurzel.classList.toggle('kopf-versteckt', neu);
        }
    }

    function planen() {
        if (!geplant) {
            geplant = true;
            window.requestAnimationFrame(anwenden);
        }
    }

    messen();
    if (typeof window.ResizeObserver === 'function') {
        new window.ResizeObserver(messen).observe(kopf);
    } else {
        window.addEventListener('resize', messen, { passive: true });
    }

    window.addEventListener('scroll', planen, { passive: true });
    // Tabbing into the header or opening the user menu brings it back.
    kopf.addEventListener('focusin', planen);
    kopf.addEventListener('toggle', planen, true);
    schmal.addEventListener('change', planen);
    ruhig.addEventListener('change', planen);
}());
