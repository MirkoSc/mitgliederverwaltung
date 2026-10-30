/*
 * Table of contents of the settings page (app/views/admin/einstellungen.php,
 * docs/spec/06-oberflaeche.md). The links are plain anchors and work without
 * this script. The script adds two things: it marks the section the reader
 * is in (aria-current="true") and, after a click, puts the focus on the
 * section heading.
 *
 * The decision lives in aktiverAbschnitt() - plain logic, tested with
 * node --test 'tests/js/**' (see tests/js/inhaltsverzeichnis.test.js).
 */
(function () {
    'use strict';

    /**
     * The section the reader is in: the last one whose top edge has passed
     * the line near the top of the window; the first one before any has.
     *
     * @param {Array<{id: string, oben: number}>} abschnitte in page order, oben = distance of the top edge to the window top
     * @param {number} linie distance from the window top that counts as "reading here"
     * @returns {string|null}
     */
    function aktiverAbschnitt(abschnitte, linie) {
        if (!abschnitte.length) {
            return null;
        }
        var aktiv = abschnitte[0].id;
        for (var i = 0; i < abschnitte.length; i++) {
            if (abschnitte[i].oben <= linie) {
                aktiv = abschnitte[i].id;
            }
        }

        return aktiv;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { aktiverAbschnitt: aktiverAbschnitt };
    }

    if (typeof document === 'undefined') {
        return;
    }

    function start() {
        var leiste = document.querySelector('[data-inhaltsverzeichnis]');
        if (!leiste) {
            return;
        }
        var links = Array.prototype.slice.call(leiste.querySelectorAll('a[href^="#"]'));
        var bereiche = links
            .map(function (link) {
                return document.getElementById(link.getAttribute('href').slice(1));
            })
            .filter(Boolean);

        function markieren() {
            var linie = window.innerHeight * 0.25;
            var id = aktiverAbschnitt(
                bereiche.map(function (b) {
                    return { id: b.id, oben: b.getBoundingClientRect().top };
                }),
                linie
            );
            links.forEach(function (link) {
                if (link.getAttribute('href') === '#' + id) {
                    link.setAttribute('aria-current', 'true');
                    if (leiste.scrollWidth > leiste.clientWidth) {
                        // Phone: the bar scrolls sideways; keep the current entry in view.
                        leiste.scrollLeft = link.offsetLeft - leiste.clientWidth / 2 + link.offsetWidth / 2;
                    }
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        }

        if (typeof IntersectionObserver === 'function') {
            // Sections entering or leaving the window are the moments the answer can change.
            var beobachter = new IntersectionObserver(markieren, { threshold: [0, 1] });
            bereiche.forEach(function (b) {
                beobachter.observe(b);
            });
        }
        window.addEventListener('scroll', markieren, { passive: true });
        window.addEventListener('resize', markieren);
        markieren();

        leiste.addEventListener('click', function (ereignis) {
            var link = ereignis.target.closest ? ereignis.target.closest('a[href^="#"]') : null;
            var ziel = link ? document.getElementById(link.getAttribute('href').slice(1)) : null;
            var ueberschrift = ziel ? ziel.querySelector('h2') : null;
            if (ueberschrift) {
                // Anchor jump happens by default; only the focus is added.
                ueberschrift.focus({ preventScroll: true });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
