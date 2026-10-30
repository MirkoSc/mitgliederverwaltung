// The page "Karte" (issue #91): builds the Leaflet map from the table
// "Anschriften auf der Karte" - every row carries data-lat/data-lon, the
// address and the members - and drives the admin's step chain
// "Jetzt Koordinaten ermitteln".
//
// The table is the source: without script, or with the map ignored, the
// page shows the same. Tiles come from this installation only
// (/app/karte/kachel/…), never from a foreign host (CSP 'self').
//
// Everything in one function scope (tests/js/skripte-nebeneinander.test.js).
(function () {
    /** Upper bound of chain steps, so a server that never says "fertig" cannot loop forever. */
    const MAX_SCHRITTE = 1000;

    /** How long to wait when the cron holds the geocoding right now. */
    const WARTEN_MS = 3000;

    /** The number on a marker: how many members live at the address. */
    function punktText(anzahl) {
        const n = Number(anzahl) || 0;

        return n > 99 ? '99+' : String(Math.max(1, n));
    }

    /** One member is a small dot, a household a bigger one. */
    function punktGroesse(anzahl) {
        return (Number(anzahl) || 0) > 1 ? 34 : 26;
    }

    /** Tooltip and accessible name of a marker. */
    function punktTitel(anschrift, anzahl) {
        const n = Number(anzahl) || 0;

        return anschrift + ': ' + n + (n === 1 ? ' Mitglied' : ' Mitglieder');
    }

    /**
     * The places from the table rows, as plain data: rows without valid
     * coordinates are left out.
     */
    function orteAus(zeilen) {
        const orte = [];
        for (const zeile of zeilen) {
            const lat = Number.parseFloat(zeile.lat);
            const lon = Number.parseFloat(zeile.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
                continue;
            }
            orte.push({ lat, lon, anschrift: zeile.anschrift || '', anzahl: Number.parseInt(zeile.anzahl, 10) || 1, element: zeile.element });
        }

        return orte;
    }

    /** Status line of the chain for one answer of the server. */
    function statusText(stand) {
        if (!stand) {
            return '';
        }
        if (stand.pause_bis) {
            return 'Der Kartendienst antwortet gerade nicht. Neuer Versuch ab ' + stand.pause_bis + ' Uhr.';
        }
        if (stand.laeuft_bereits) {
            return 'Die Ermittlung läuft gerade im Hintergrund – einen Moment …';
        }
        if (stand.offen === 0) {
            return 'Alle Anschriften sind ermittelt.';
        }

        return (stand.text || '') + ' – noch ' + stand.offen + ' offen …';
    }

    /** Whether the chain needs another step after this answer. */
    function weiter(stand) {
        return !!stand && !stand.fertig;
    }

    function initKarte() {
        const flaeche = document.querySelector('#karte');
        if (flaeche === null || typeof window.L === 'undefined') {
            return;
        }
        const L = window.L;
        const tabelle = document.querySelector(flaeche.dataset.orte || '#karte-orte');
        if (tabelle === null) {
            return;
        }

        const orte = orteAus(Array.from(tabelle.querySelectorAll('tbody tr')).map((zeile) => ({
            lat: zeile.dataset.lat,
            lon: zeile.dataset.lon,
            anschrift: (zeile.querySelector('[data-karte-anschrift]') || {}).textContent || '',
            anzahl: (zeile.querySelector('[data-karte-anzahl]') || {}).textContent || '1',
            element: zeile.querySelector('[data-karte-mitglieder]'),
        })));
        if (orte.length === 0) {
            flaeche.hidden = true;
            return;
        }

        const zoomMin = Number.parseInt(flaeche.dataset.zoomMin, 10) || 5;
        const zoomMax = Number.parseInt(flaeche.dataset.zoomMax, 10) || 18;
        // No animations in the Barrierefrei-Modus or when the system asks
        // for reduced motion (06 section 4).
        const ruhig = document.documentElement.classList.contains('modus-barrierefrei')
            || (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        const karte = L.map(flaeche, {
            minZoom: zoomMin,
            maxZoom: zoomMax,
            zoomAnimation: !ruhig,
            fadeAnimation: !ruhig,
            markerZoomAnimation: !ruhig,
        });
        karte.attributionControl.setPrefix(false);
        L.tileLayer(flaeche.dataset.kacheln, {
            minZoom: zoomMin,
            maxZoom: zoomMax,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende',
        }).addTo(karte);

        const grenzen = [];
        for (const ort of orte) {
            const groesse = punktGroesse(ort.anzahl);
            const titel = punktTitel(ort.anschrift, ort.anzahl);
            // The number as a text node - never markup built from data.
            const zahl = document.createElement('span');
            zahl.textContent = punktText(ort.anzahl);
            const punkt = L.marker([ort.lat, ort.lon], {
                title: titel,
                alt: titel,
                keyboard: true,
                riseOnHover: true,
                icon: L.divIcon({
                    className: ort.anzahl > 1 ? 'karte-punkt karte-punkt-mehr' : 'karte-punkt',
                    html: zahl,
                    iconSize: [groesse, groesse],
                }),
            });

            punkt.bindPopup(() => {
                const inhalt = document.createElement('div');
                inhalt.className = 'karte-popup';
                const kopf = document.createElement('p');
                kopf.className = 'karte-popup-anschrift';
                kopf.textContent = ort.anschrift;
                inhalt.appendChild(kopf);
                if (ort.element) {
                    inhalt.appendChild(ort.element.firstElementChild ? ort.element.firstElementChild.cloneNode(true) : document.createElement('ul'));
                }

                return inhalt;
            });
            // Leaflet makes the marker a focusable button; its name is the
            // address, not just the number inside. Set once Leaflet has
            // drawn it (only after the map has its view).
            punkt.on('add', () => {
                const element = punkt.getElement();
                if (element) {
                    element.setAttribute('aria-label', titel);
                }
            });
            punkt.addTo(karte);
            grenzen.push([ort.lat, ort.lon]);
        }

        if (grenzen.length === 1) {
            karte.setView(grenzen[0], Math.min(15, zoomMax));
        } else {
            karte.fitBounds(grenzen, { padding: [24, 24], maxZoom: Math.min(16, zoomMax) });
        }
    }

    function initErmitteln() {
        const formular = document.querySelector('#karte-ermitteln');
        if (formular === null) {
            return;
        }
        const knopf = formular.querySelector('button');
        const status = formular.querySelector('#karte-ermitteln-status');
        const csrf = formular.dataset.csrf || '';

        async function schritt() {
            const daten = new FormData();
            daten.append('_csrf', csrf);
            const antwort = await fetch(formular.action, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'X-Hintergrund': '1', Accept: 'application/json' },
                body: daten,
            });
            if (!antwort.ok) {
                throw new Error('Der Server antwortet nicht (HTTP ' + antwort.status + ').');
            }

            return antwort.json();
        }

        formular.addEventListener('submit', async (ereignis) => {
            ereignis.preventDefault();
            knopf.disabled = true;
            status.hidden = false;
            status.textContent = 'Koordinaten werden ermittelt …';
            let stand = null;
            try {
                for (let i = 0; i < MAX_SCHRITTE; i++) {
                    stand = await schritt();
                    status.textContent = statusText(stand);
                    if (!weiter(stand)) {
                        break;
                    }
                    if (stand.laeuft_bereits) {
                        await new Promise((fertig) => setTimeout(fertig, WARTEN_MS));
                    }
                }
                if (stand && stand.offen === 0) {
                    // The new markers belong on the map.
                    window.location.reload();
                    return;
                }
            } catch (problem) {
                status.textContent = problem.message + ' Ein neuer Klick setzt fort.';
            }
            knopf.disabled = false;
        });
    }

    if (typeof document !== 'undefined') {
        initKarte();
        initErmitteln();
    }

    // Node (tests/js) loads the same file for the pure helpers above.
    if (typeof module === 'object' && module.exports) {
        module.exports = { punktText, punktGroesse, punktTitel, orteAus, statusText, weiter };
    }
})();
