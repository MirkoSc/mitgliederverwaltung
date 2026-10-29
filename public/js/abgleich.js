// Merge view and step chain of the reconciliation in the settings section
// Import (issue #34, docs/spec/10-import.md section 4): "Alle an/aus" per
// section, the tick "ganz löschen" tied to "austreten", and the chain - one
// request starts it with the ticked member numbers, then one request per
// step until the server reports it done. No single request runs long
// (CLAUDE.md section 1). Patterned on import.js.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). Everything sits in one
// function scope so it never clashes with import.js on the same origin.
(function () {
    /** Upper bound of steps, so a server that never says "fertig" cannot loop forever. */
    const MAX_SCHRITTE = 100000;

    /** The sections whose ticks are sent; each as one comma-separated list. */
    const GRUPPEN = ['neu', 'aendern', 'austritt', 'loeschen'];

    /** The ticked values as "1,2,3" (thousands of fields would run into max_input_vars). */
    function liste(werte) {
        return (werte || []).filter((wert) => /^\d+$/.test(String(wert))).join(',');
    }

    /** How many rows are ticked over all sections; "ganz löschen" is part of "austreten". */
    function anzahlGewaehlt(auswahl) {
        const austritt = new Set([...(auswahl.austritt || []), ...(auswahl.loeschen || [])]);

        return (auswahl.neu || []).length + (auswahl.aendern || []).length + austritt.size;
    }

    function prozent(teil, ganz) {
        if (!ganz || ganz <= 0) {
            return null;
        }

        return Math.min(100, Math.round((100 * (teil || 0)) / ganz));
    }

    function fortschritt(stand) {
        if (!stand) {
            return null;
        }
        if (stand.fertig) {
            return 100;
        }

        return prozent(stand.offset, stand.gesamt);
    }

    function statusText(stand) {
        if (!stand) {
            return '';
        }
        if (stand.fertig) {
            return 'Der Abgleich ist abgeschlossen.';
        }
        const gesamt = stand.gesamt || 0;
        if (gesamt === 0) {
            return 'Abgleich wird gestartet …';
        }
        const fertig = Math.min(stand.offset || 0, gesamt);

        return (
            'Übernommen: ' +
            fertig.toLocaleString('de-DE') +
            ' von ' +
            gesamt.toLocaleString('de-DE') +
            ' Zeilen …'
        );
    }

    function weiter(stand) {
        return !!stand && !stand.fertig;
    }

    function initAbgleich() {
        const formular = document.querySelector('#abgleich-starten') || document.querySelector('#abgleich-weiter');
        if (formular === null) {
            return;
        }

        const csrf = formular.dataset.csrf || '';
        const schrittPfad = formular.dataset.schritt || '/admin/import/abgleich/schritt';
        const start = document.querySelector('#abgleich-start');
        const fortsetzen = document.querySelector('#abgleich-fortsetzen');
        const verlauf = document.querySelector('#abgleich-verlauf');
        const balken = document.querySelector('#abgleich-fortschritt');
        const status = document.querySelector('#abgleich-status');
        const fehler = document.querySelector('#abgleich-fehler');
        const knoepfe = [start, fortsetzen].filter((knopf) => knopf !== null);

        function haken(gruppe) {
            return Array.from(formular.querySelectorAll('input[type="checkbox"][name="' + gruppe + '"]'));
        }

        function partner(gruppe, wert) {
            return haken(gruppe).find((feld) => feld.value === wert) || null;
        }

        // "Alle an" / "Alle aus" of a section.
        formular.querySelectorAll('[data-abgleich-alle]').forEach((knopf) => {
            knopf.addEventListener('click', () => {
                const an = knopf.dataset.an === '1';
                haken(knopf.dataset.abgleichAlle).forEach((feld) => {
                    feld.checked = an;
                });
                if (knopf.dataset.abgleichAlle === 'austritt' && !an) {
                    haken('loeschen').forEach((feld) => {
                        feld.checked = false;
                    });
                }
            });
        });

        // "ganz löschen" goes with "austreten": ticking it ticks the row,
        // unticking the row unticks it.
        formular.addEventListener('change', (ereignis) => {
            const feld = ereignis.target;
            if (!(feld instanceof HTMLInputElement) || feld.type !== 'checkbox') {
                return;
            }
            if (feld.name === 'loeschen' && feld.checked) {
                const zeile = partner('austritt', feld.value);
                if (zeile !== null) {
                    zeile.checked = true;
                }
            }
            if (feld.name === 'austritt' && !feld.checked) {
                const loeschen = partner('loeschen', feld.value);
                if (loeschen !== null) {
                    loeschen.checked = false;
                }
            }
        });

        function zeigen(stand) {
            status.textContent = statusText(stand) || 'Abgleich läuft …';
            const wert = fortschritt(stand);
            if (wert === null) {
                balken.removeAttribute('value');
            } else {
                balken.value = wert;
            }
        }

        async function senden(pfad, felder) {
            const daten = new FormData();
            daten.append('_csrf', csrf);
            Object.entries(felder || {}).forEach(([name, wert]) => daten.append(name, wert));
            const antwort = await fetch(pfad, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'X-Hintergrund': '1', Accept: 'application/json' },
                body: daten,
            });
            let inhalt = {};
            try {
                inhalt = await antwort.json();
            } catch (problem) {
                inhalt = {};
            }
            if (!antwort.ok || inhalt.fehler) {
                throw new Error(inhalt.fehler || 'Der Server antwortet nicht (HTTP ' + antwort.status + ').');
            }

            return inhalt;
        }

        async function laufe(ersterStand) {
            let stand = ersterStand;
            let schritte = 0;
            zeigen(stand);
            while (weiter(stand) && schritte < MAX_SCHRITTE) {
                stand = await senden(schrittPfad);
                zeigen(stand);
                schritte++;
            }
            if (stand && stand.fertig) {
                // The summary is a flash message on the reloaded page.
                window.location.reload();
            }
        }

        async function ausfuehren(arbeit) {
            knoepfe.forEach((knopf) => {
                knopf.disabled = true;
            });
            verlauf.hidden = false;
            fehler.hidden = true;
            try {
                await arbeit();
            } catch (problem) {
                status.textContent = 'Der Abgleich ist nicht weitergegangen.';
                fehler.textContent = problem.message;
                fehler.hidden = false;
            } finally {
                knoepfe.forEach((knopf) => {
                    knopf.disabled = false;
                });
            }
        }

        if (start !== null) {
            formular.addEventListener('submit', (ereignis) => {
                ereignis.preventDefault();
                const auswahl = {};
                GRUPPEN.forEach((gruppe) => {
                    auswahl[gruppe] = haken(gruppe).filter((feld) => feld.checked).map((feld) => feld.value);
                });
                if (anzahlGewaehlt(auswahl) === 0) {
                    verlauf.hidden = false;
                    status.textContent = '';
                    fehler.textContent = 'Es ist nichts angehakt. Bitte die Zeilen anhaken, die übernommen werden sollen.';
                    fehler.hidden = false;

                    return;
                }
                const felder = {};
                GRUPPEN.forEach((gruppe) => {
                    felder[gruppe] = liste(auswahl[gruppe]);
                });
                const datum = formular.querySelector('[name="austrittsdatum"]');
                felder.austrittsdatum = datum === null ? '' : datum.value;
                ausfuehren(async () => {
                    const stand = await senden(formular.getAttribute('action'), felder);
                    await laufe(stand);
                });
            });
        }

        if (fortsetzen !== null) {
            fortsetzen.addEventListener('click', () => {
                ausfuehren(() => laufe({ phase: 'abgleich', fertig: false, offset: 0, gesamt: 0 }));
            });
        }
    }

    if (typeof document !== 'undefined') {
        initAbgleich();
    }

    // Node (tests/js) loads the same file for the pure helpers above;
    // browsers ignore this block because `module` does not exist there.
    if (typeof module === 'object' && module.exports) {
        module.exports = { liste, anzahlGewaehlt, statusText, fortschritt, weiter, prozent };
    }
})();
