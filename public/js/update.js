// Drives the update step chain in the settings section Update (issue #4,
// docs/spec/04-installation-und-update.md section 3): one request per step,
// so no single request runs long (CLAUDE.md section 1). Taken over from
// Vereinsbelege.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). Everything the script needs
// - the CSRF token and the step order - comes from data-* attributes on the
// #update element, never from an inline block.
//
// Everything sits in one function scope: the settings page loads several
// of these scripts side by side, and top-level declarations of classic
// scripts share one global scope (a second "const MAX_SCHRITTE" stopped the
// later script with a SyntaxError, issue #36).
(function () {
    /**
     * The steps still to run after `abgeschlossen`. An unknown or missing value
     * means "nothing done yet", so the whole chain runs - which is also the
     * repair path: every step is idempotent, and redoing one is always allowed.
     */
    function naechsteSchritte(alle, abgeschlossen) {
        const index = alle.indexOf(abgeschlossen);

        return index === -1 ? alle.slice() : alle.slice(index + 1);
    }

    /**
     * The chain starting AT `schritt`, i.e. what a retry runs: the failed step
     * itself plus everything after it. Every step is idempotent, so repeating
     * the one that failed is always safe.
     */
    function abSchritt(alle, schritt) {
        const index = alle.indexOf(schritt);

        return index === -1 ? [] : alle.slice(index);
    }

    /**
     * Whether a failure in `schritt` is worth offering "back to the previous
     * release": only once the new release is the running one, i.e. in migrate
     * and finish. Before that, releases/_prev is the release of an OLDER update
     * - "rolling back" there would be a downgrade.
     */
    function zurueckrollenAngebracht(alle, schritt) {
        const index = alle.indexOf(schritt);

        return index !== -1 && index > alle.indexOf('switch');
    }

    /** Upper bound of calls of one step, so a server that keeps answering `weiter` cannot loop forever. */
    const MAX_WIEDERHOLUNGEN = 100000;

    /**
     * Whether the step that gave this answer wants to be called again: the
     * backup step answers `weiter` while its dump is not through.
     */
    function nochmal(state) {
        return Boolean(state && state.weiter === true && !state.fehler);
    }

    /** The last line of the step log - the admin page shows only that one. */
    function letzteMeldung(state) {
        const meldungen = (state && state.meldungen) || [];

        return meldungen.length === 0 ? '' : meldungen[meldungen.length - 1];
    }

    const SCHRITT_TEXTE = {
        check: 'Versionscheck',
        download: 'Download',
        extract: 'Entpacken',
        backup: 'Backup',
        switch: 'Umschalten',
        migrate: 'Datenbank aktualisieren',
        finish: 'Abschluss und Selbsttest',
        rollback: 'Rollback',
    };

    /** The German name of a step for the status line. */
    function schrittName(name) {
        return SCHRITT_TEXTE[name] || name;
    }

    function initUpdate() {
        const wurzel = document.querySelector('#update');
        if (wurzel === null) {
            return;
        }

        const csrf = wurzel.dataset.csrf || '';
        const alleSchritte = (wurzel.dataset.schritte || '').split(',').filter((s) => s !== '');

        const suchen = wurzel.querySelector('#update-suchen');
        const starten = wurzel.querySelector('#update-starten');
        const fortsetzen = wurzel.querySelector('#update-fortsetzen');
        const verlauf = wurzel.querySelector('#update-verlauf');
        const status = wurzel.querySelector('#update-status');
        const log = wurzel.querySelector('#update-log');
        const fehler = wurzel.querySelector('#update-fehler');
        const aktionen = wurzel.querySelector('#update-aktionen');
        const wiederholen = wurzel.querySelector('#update-wiederholen');
        const rollbackFehler = wurzel.querySelector('#update-rollback-fehler');
        const fertigZeile = wurzel.querySelector('#update-fertig');
        const neuladen = wurzel.querySelector('#update-neuladen');
        // Outside #update: it belongs to the section, not to the running chain.
        const rollback = document.querySelector('#update-rollback');

        let offeneSchritte = [];
        let fehlgeschlagenerSchritt = null;

        function notiere(text) {
            const eintrag = document.createElement('li');
            eintrag.textContent = text;
            log.appendChild(eintrag);
        }

        function zeigeFehler(text, schritt) {
            fehler.textContent = text;
            fehler.hidden = false;
            aktionen.hidden = false;
            wiederholen.hidden = schritt === undefined || schritt === null;
            rollbackFehler.hidden = !(schritt && zurueckrollenAngebracht(alleSchritte, schritt));
        }

        function sperren(gesperrt) {
            for (const knopf of [suchen, starten, fortsetzen, rollback]) {
                if (knopf) {
                    knopf.disabled = gesperrt;
                }
            }
        }

        async function schritt(name) {
            const antwort = await fetch('/admin/update/schritt/' + name, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
            });
            let daten = {};
            try {
                daten = await antwort.json();
            } catch (problem) {
                daten = {};
            }

            if (!antwort.ok || daten.fehler) {
                throw new Error(daten.fehler || 'Der Server antwortet nicht (HTTP ' + antwort.status + ').');
            }

            return daten;
        }

        async function laufe(schritte) {
            verlauf.hidden = false;
            fehler.hidden = true;
            aktionen.hidden = true;
            fertigZeile.hidden = true;
            sperren(true);

            try {
                for (const name of schritte) {
                    status.textContent = schrittName(name) + ' läuft …';
                    try {
                        let state = await schritt(name);
                        for (let teil = 2; nochmal(state); teil++) {
                            if (teil > MAX_WIEDERHOLUNGEN) {
                                throw new Error('Der Schritt endet nicht.');
                            }
                            status.textContent = schrittName(name) + ' läuft … (Teil ' + teil + ')';
                            state = await schritt(name);
                        }
                        notiere(schrittName(name) + ': ' + letzteMeldung(state));
                    } catch (problem) {
                        fehlgeschlagenerSchritt = name === 'rollback' ? null : name;
                        status.textContent = 'Abgebrochen.';
                        zeigeFehler(schrittName(name) + ' ist fehlgeschlagen: ' + problem.message, fehlgeschlagenerSchritt);

                        return;
                    }
                }

                fehlgeschlagenerSchritt = null;
                status.textContent = schritte.includes('rollback')
                    ? 'Das vorherige Release ist wieder aktiv.'
                    : 'Das Update ist abgeschlossen.';
                fertigZeile.hidden = false;
            } finally {
                sperren(false);
            }
        }

        function zurueckgehen() {
            if (!window.confirm('Wirklich auf das vorherige Release zurückgehen? Die Daten bleiben, wie sie sind.')) {
                return Promise.resolve();
            }

            return laufe(['rollback']);
        }

        suchen.addEventListener('click', async () => {
            suchen.disabled = true;
            verlauf.hidden = false;
            fehler.hidden = true;
            aktionen.hidden = true;
            starten.hidden = true;
            status.textContent = 'Suche nach Updates …';

            try {
                const state = await schritt('check');
                notiere(letzteMeldung(state));

                if (state.ziel_version && !state.fertig) {
                    offeneSchritte = naechsteSchritte(alleSchritte, 'check');
                    starten.hidden = false;
                    status.textContent = 'Das Update auf Version ' + state.ziel_version + ' ist bereit.';
                } else {
                    status.textContent = 'Es gibt kein neueres Release.';
                }
            } catch (problem) {
                status.textContent = 'Abgebrochen.';
                zeigeFehler('Der Versionscheck ist fehlgeschlagen: ' + problem.message, null);
            } finally {
                suchen.disabled = false;
            }
        });

        starten.addEventListener('click', () => {
            starten.hidden = true;

            return laufe(offeneSchritte);
        });

        if (fortsetzen) {
            fortsetzen.addEventListener('click', () => {
                fortsetzen.hidden = true;

                return laufe(naechsteSchritte(alleSchritte, wurzel.dataset.fortsetzenNach || ''));
            });
        }

        wiederholen.addEventListener('click', () => {
            if (fehlgeschlagenerSchritt === null) {
                return Promise.resolve();
            }

            return laufe(abSchritt(alleSchritte, fehlgeschlagenerSchritt));
        });

        // A button and not a link to /admin#update: the same address with only a
        // fragment would not reload the page, and the new version should show.
        neuladen.addEventListener('click', () => window.location.reload());

        rollbackFehler.addEventListener('click', zurueckgehen);
        if (rollback) {
            rollback.addEventListener('click', zurueckgehen);
        }
    }

    if (typeof document !== 'undefined') {
        initUpdate();
    }

    // Node (tests/js) loads the same file for the pure helpers above; browsers
    // ignore this block because `module` does not exist there.
    if (typeof module === 'object' && module.exports) {
        module.exports = { naechsteSchritte, abSchritt, zurueckrollenAngebracht, letzteMeldung, nochmal, schrittName };
    }
})();
