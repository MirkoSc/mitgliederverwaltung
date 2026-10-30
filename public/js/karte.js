// The page "Karte" (issue #91): builds the Leaflet map from the table
// "Anschriften auf der Karte" - every row carries data-lat/data-lon, the
// address and the members - and drives the admin's step chain
// "Jetzt Koordinaten ermitteln". A row marked data-fokus (the link
// "Auf der großen Karte zeigen" at a member, issue #106) is centred and
// its popup opened.
//
// Also the small map of the block "Wohnort auf der Karte" in an opened
// member (issue #106, [data-wohnort-karte]): one marker, street level.
// The member is swapped in by htmx, so it is built again after every swap.
// With data-setzen (whoever may edit, issue #108) a click on the map or
// dragging the marker fills the fields "Breite"/"Länge" of the details
// named there; typing in them moves the marker. Saving is the form's
// button - nothing is sent from here. Without a dot the map opens at
// data-mitte-lat/-lon/-zoom.
//
// "Alle neu ermitteln" (issue #108, admin) first forgets the automatic
// results (one POST), then runs the same chain as "Jetzt Koordinaten
// ermitteln".
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

    /** Zoom of one place: about the street and its neighbours. */
    const STRASSE_ZOOM = 17;

    /** The number on a marker: how many members live at the address. */
    function punktText(anzahl) {
        const n = Number(anzahl) || 0;

        return n > 99 ? '99+' : String(Math.max(1, n));
    }

    /** One member is a small dot, a household a bigger one. */
    function punktGroesse(anzahl) {
        return (Number(anzahl) || 0) > 1 ? 34 : 26;
    }

    /** What a marker says about how exact it is (issue #108); '' for a found address. */
    const GENAUIGKEIT = { ungefaehr: 'ungefähr', von_hand: 'von Hand' };

    /** Tooltip and accessible name of a marker - an approximate or hand-set dot says so. */
    function punktTitel(anschrift, anzahl, genauigkeit) {
        const n = Number(anzahl) || 0;
        const zusatz = GENAUIGKEIT[genauigkeit] || '';

        return anschrift + ': ' + n + (n === 1 ? ' Mitglied' : ' Mitglieder') + (zusatz === '' ? '' : ' (' + zusatz + ')');
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
            orte.push({
                lat,
                lon,
                anschrift: zeile.anschrift || '',
                anzahl: Number.parseInt(zeile.anzahl, 10) || 1,
                element: zeile.element,
                fokus: zeile.fokus === true,
                genauigkeit: Object.prototype.hasOwnProperty.call(GENAUIGKEIT, zeile.genauigkeit) ? zeile.genauigkeit : '',
            });
        }

        return orte;
    }

    /**
     * The one place of a small map from its data-* attributes (lat, lon,
     * titel), or null when the coordinates are no coordinates.
     */
    function wohnortAus(daten) {
        const orte = orteAus([{ lat: daten.lat, lon: daten.lon, anschrift: daten.titel || '', anzahl: '1', genauigkeit: daten.genauigkeit || '' }]);

        return orte.length === 1 ? orte[0] : null;
    }

    /**
     * Where a small map without a dot opens (issue #108): data-mitte-lat,
     * -lon, -zoom; null when they are missing or no coordinates.
     */
    function mitteAus(daten) {
        const lat = Number.parseFloat(daten.mitteLat);
        const lon = Number.parseFloat(daten.mitteLon);
        if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
            return null;
        }
        const zoom = Number.parseInt(daten.mitteZoom, 10);

        return { lat, lon, zoom: Number.isFinite(zoom) ? zoom : 13 };
    }

    /**
     * A point typed into the fields "Breite"/"Länge" (decimal comma
     * allowed), or null when it is no point.
     */
    function punktAusFeldern(breite, laenge) {
        const zahl = (text) => {
            const wert = String(text || '').trim().replace(',', '.');

            return /^[+-]?\d{1,3}(\.\d{1,12})?$/.test(wert) ? Number.parseFloat(wert) : Number.NaN;
        };
        const lat = zahl(breite);
        const lon = zahl(laenge);
        if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
            return null;
        }

        return { lat, lon };
    }

    /** A coordinate as the fields show it: six places, a point - what the server reads. */
    function feldText(zahl) {
        return Number(zahl).toFixed(6);
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
            fokus: zeile.hasAttribute('data-fokus'),
            genauigkeit: zeile.dataset.genauigkeit || '',
        })));
        if (orte.length === 0) {
            flaeche.hidden = true;
            return;
        }

        const karte = neueKarte(L, flaeche);
        const zoomMax = karte.getMaxZoom();

        const grenzen = [];
        let fokus = null;
        for (const ort of orte) {
            const punkt = neuerPunkt(L, ort);

            punkt.bindPopup(() => {
                const inhalt = document.createElement('div');
                inhalt.className = 'karte-popup';
                const kopf = document.createElement('p');
                kopf.className = 'karte-popup-anschrift';
                kopf.textContent = ort.anschrift + (GENAUIGKEIT[ort.genauigkeit] ? ' (' + GENAUIGKEIT[ort.genauigkeit] + ')' : '');
                inhalt.appendChild(kopf);
                if (ort.element) {
                    inhalt.appendChild(ort.element.firstElementChild ? ort.element.firstElementChild.cloneNode(true) : document.createElement('ul'));
                }

                return inhalt;
            });
            punkt.addTo(karte);
            grenzen.push([ort.lat, ort.lon]);
            if (ort.fokus && fokus === null) {
                fokus = { ort, punkt };
            }
        }

        if (fokus !== null) {
            karte.setView([fokus.ort.lat, fokus.ort.lon], Math.min(STRASSE_ZOOM, zoomMax));
            fokus.punkt.openPopup();
        } else if (grenzen.length === 1) {
            karte.setView(grenzen[0], Math.min(15, zoomMax));
        } else {
            karte.fitBounds(grenzen, { padding: [24, 24], maxZoom: Math.min(16, zoomMax) });
        }
    }

    /**
     * A Leaflet map on $flaeche with the tiles of this installation
     * (data-kacheln, data-zoom-min/-max) and the OpenStreetMap notice.
     */
    function neueKarte(L, flaeche, optionen) {
        const zoomMin = Number.parseInt(flaeche.dataset.zoomMin, 10) || 5;
        const zoomMax = Number.parseInt(flaeche.dataset.zoomMax, 10) || 18;
        // No animations in the Barrierefrei-Modus or when the system asks
        // for reduced motion (06 section 4).
        const ruhig = document.documentElement.classList.contains('modus-barrierefrei')
            || (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        const karte = L.map(flaeche, Object.assign({
            minZoom: zoomMin,
            maxZoom: zoomMax,
            zoomAnimation: !ruhig,
            fadeAnimation: !ruhig,
            markerZoomAnimation: !ruhig,
        }, optionen || {}));
        karte.attributionControl.setPrefix(false);
        L.tileLayer(flaeche.dataset.kacheln, {
            minZoom: zoomMin,
            maxZoom: zoomMax,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende',
        }).addTo(karte);

        return karte;
    }

    /** A marker: a coin with the number of members, named by the address. */
    function neuerPunkt(L, ort, mehr) {
        const groesse = punktGroesse(ort.anzahl);
        const titel = punktTitel(ort.anschrift, ort.anzahl, ort.genauigkeit);
        // The number as a text node - never markup built from data.
        const zahl = document.createElement('span');
        zahl.textContent = punktText(ort.anzahl);
        const punkt = L.marker([ort.lat, ort.lon], Object.assign({
            title: titel,
            alt: titel,
            keyboard: true,
            riseOnHover: true,
            icon: L.divIcon({
                // An approximate dot is drawn hollow (issue #108); the
                // title says it in words as well.
                className: 'karte-punkt' + (ort.anzahl > 1 ? ' karte-punkt-mehr' : '') + (ort.genauigkeit === 'ungefaehr' ? ' karte-punkt-ungefaehr' : ''),
                html: zahl,
                iconSize: [groesse, groesse],
            }),
        }, mehr || {}));
        // Leaflet makes the marker a focusable button; its name is the
        // address, not just the number inside. Set once Leaflet has
        // drawn it (only after the map has its view).
        punkt.on('add', () => {
            const element = punkt.getElement();
            if (element) {
                element.setAttribute('aria-label', titel);
            }
        });

        return punkt;
    }

    /**
     * The small maps in an opened member (issue #106): one marker at
     * street level. The text above the map says the same, so the map
     * stays hidden when it cannot be built. Each one only once - htmx
     * settles the whole page, not only the swapped part.
     */
    function initWohnorte() {
        if (typeof window.L === 'undefined') {
            return;
        }
        const L = window.L;
        for (const flaeche of document.querySelectorAll('[data-wohnort-karte]')) {
            if (flaeche.dataset.wohnortBereit === '1') {
                continue;
            }
            const ort = wohnortAus(flaeche.dataset);
            const mitte = ort === null ? mitteAus(flaeche.dataset) : null;
            if (ort === null && mitte === null) {
                continue;
            }
            flaeche.dataset.wohnortBereit = '1';
            flaeche.hidden = false;
            // The page scrolls past the small map: the wheel must not zoom it.
            const karte = neueKarte(L, flaeche, { scrollWheelZoom: false });
            const zoomMax = karte.getMaxZoom();
            let punkt = null;
            if (ort !== null) {
                karte.setView([ort.lat, ort.lon], Math.min(STRASSE_ZOOM, zoomMax));
                punkt = neuerPunkt(L, ort, flaeche.dataset.setzen ? { draggable: true } : undefined).addTo(karte);
            } else {
                karte.setView([mitte.lat, mitte.lon], Math.min(mitte.zoom, zoomMax));
            }
            if (flaeche.dataset.setzen) {
                punktSetzbar(L, karte, flaeche, punkt);
            }
        }
    }

    /**
     * Setting the point by hand on a small map (issue #108): click or drag
     * fills the fields, typing moves the marker. The details with the
     * fields opens, a status line says that nothing is saved yet.
     */
    function punktSetzbar(L, karte, flaeche, anfangsPunkt) {
        const bereich = document.querySelector(flaeche.dataset.setzen);
        if (bereich === null) {
            return;
        }
        const breite = bereich.querySelector('[name="breite"]');
        const laenge = bereich.querySelector('[name="laenge"]');
        const stand = bereich.querySelector('[data-wohnort-punkt-stand]');
        if (breite === null || laenge === null) {
            return;
        }
        let punkt = anfangsPunkt;
        const titel = flaeche.dataset.titel || '';

        function melden() {
            if (stand) {
                stand.textContent = 'Punkt gesetzt, noch nicht gespeichert. „Punkt speichern“ übernimmt ihn.';
            }
        }

        function setzen(latlng, ausFeldern) {
            const lage = latlng.wrap();
            if (punkt === null) {
                punkt = neuerPunkt(L, { lat: lage.lat, lon: lage.lng, anschrift: titel, anzahl: 1 }, { draggable: true }).addTo(karte);
                punkt.on('dragend', () => setzen(punkt.getLatLng(), false));
            } else {
                punkt.setLatLng(lage);
            }
            if (!ausFeldern) {
                breite.value = feldText(lage.lat);
                laenge.value = feldText(lage.lng);
                bereich.open = true;
            }
            melden();
        }

        if (punkt !== null) {
            punkt.on('dragend', () => setzen(punkt.getLatLng(), false));
        }
        karte.on('click', (ereignis) => setzen(ereignis.latlng, false));
        for (const feld of [breite, laenge]) {
            feld.addEventListener('change', () => {
                const neu = punktAusFeldern(breite.value, laenge.value);
                if (neu !== null) {
                    setzen(L.latLng(neu.lat, neu.lon), true);
                    karte.panTo([neu.lat, neu.lon]);
                }
            });
        }
    }

    /**
     * The chain of "Jetzt Koordinaten ermitteln": steps until the server
     * says "fertig", the state in $status. Reloads the page when all is
     * through - the new markers belong on the map.
     */
    async function kette(adresse, csrf, status) {
        async function schritt() {
            const daten = new FormData();
            daten.append('_csrf', csrf);
            const antwort = await fetch(adresse, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'X-Hintergrund': '1', Accept: 'application/json' },
                body: daten,
            });
            if (!antwort.ok) {
                throw new Error('Der Server antwortet nicht (HTTP ' + antwort.status + ').');
            }

            return antwort.json();
        }

        let stand = null;
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
            window.location.reload();
            return true;
        }

        return false;
    }

    function initErmitteln() {
        const formular = document.querySelector('#karte-ermitteln');
        if (formular === null) {
            return;
        }
        const knopf = formular.querySelector('button');
        const status = formular.querySelector('#karte-ermitteln-status');
        const csrf = formular.dataset.csrf || '';

        formular.addEventListener('submit', async (ereignis) => {
            ereignis.preventDefault();
            knopf.disabled = true;
            status.hidden = false;
            status.textContent = 'Koordinaten werden ermittelt …';
            try {
                if (await kette(formular.action, csrf, status)) {
                    return;
                }
            } catch (problem) {
                status.textContent = problem.message + ' Ein neuer Klick setzt fort.';
            }
            knopf.disabled = false;
        });
    }

    /**
     * "Alle neu ermitteln" (issue #108): one POST forgets the automatic
     * results, then the chain of the button above (data-kette) runs. The
     * tick of the question is checked by the browser (required) and again
     * by the server.
     */
    function initNeuErmitteln() {
        const formular = document.querySelector('#karte-neu-ermitteln');
        if (formular === null) {
            return;
        }
        const knopf = formular.querySelector('button[type="submit"]');
        const status = formular.querySelector('#karte-neu-status');
        const csrf = formular.dataset.csrf || '';

        formular.addEventListener('submit', async (ereignis) => {
            ereignis.preventDefault();
            knopf.disabled = true;
            status.hidden = false;
            status.textContent = 'Die bisherigen Ergebnisse werden vergessen …';
            try {
                const daten = new FormData(formular);
                const antwort = await fetch(formular.action, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrf, 'X-Hintergrund': '1', Accept: 'application/json' },
                    body: daten,
                });
                if (!antwort.ok) {
                    throw new Error('Der Server antwortet nicht (HTTP ' + antwort.status + ').');
                }
                status.textContent = statusText(await antwort.json());
                if (await kette(formular.dataset.kette, csrf, status)) {
                    return;
                }
            } catch (problem) {
                status.textContent = problem.message + ' Mit „Jetzt Koordinaten ermitteln“ geht es weiter.';
            }
            knopf.disabled = false;
        });
    }

    if (typeof document !== 'undefined') {
        initKarte();
        initErmitteln();
        initNeuErmitteln();
        initWohnorte();
        document.addEventListener('htmx:afterSettle', initWohnorte);
    }

    // Node (tests/js) loads the same file for the pure helpers above.
    if (typeof module === 'object' && module.exports) {
        module.exports = { punktText, punktGroesse, punktTitel, orteAus, wohnortAus, mitteAus, punktAusFeldern, feldText, statusText, weiter };
    }
})();
