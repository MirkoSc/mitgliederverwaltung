// Drives the backup step chain of the settings section Backups (issue #12,
// docs/spec/04-installation-und-update.md section 6): one request starts the
// chain, then one request per step until the server reports the backup
// finished. No single request runs long (CLAUDE.md section 1). Taken over
// from Vereinsbelege.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on the form.
//
// Everything sits in one function scope: the settings page loads several
// of these scripts side by side, and top-level declarations of classic
// scripts share one global scope (a second "const MAX_SCHRITTE" stopped the
// later script with a SyntaxError, issue #36).
(function () {
    /** Upper bound of steps, so a server that never says "fertig" cannot loop forever. */
    const MAX_SCHRITTE = 100000;

    /** Share of a total as whole percent, never above 100; null without a total. */
    function prozent(teil, ganz) {
        if (!ganz || ganz <= 0) {
            return null;
        }

        return Math.min(100, Math.round((100 * (teil || 0)) / ganz));
    }

    /**
     * How far the chain is, in percent - or null where the server cannot know
     * (the dump does not know its size in advance); the bar then shows that it
     * is busy without a figure.
     */
    function fortschritt(stand) {
        if (!stand) {
            return null;
        }
        if (stand.fertig) {
            return 100;
        }
        switch (stand.phase) {
            case 'dateien':
                return stand.dateien_gesamt > 0 ? prozent(stand.dateien, stand.dateien_gesamt) : null;
            case 'verschluesseln':
                return prozent(stand.verschluesselt, stand.zip_bytes);
            default:
                return null;
        }
    }

    /** The status line for a state answered by the server. */
    function statusText(stand) {
        if (!stand) {
            return '';
        }
        if (stand.fertig) {
            return 'Das Backup ist erstellt.';
        }
        switch (stand.phase) {
            case 'dump': {
                const zeilen = stand.dump_zeilen || 0;

                return zeilen === 0
                    ? 'Datenbank wird gesichert …'
                    : 'Datenbank wird gesichert … (' + zeilen.toLocaleString('de-DE') + ' Zeilen)';
            }
            case 'dateien': {
                const gesamt = stand.dateien_gesamt || 0;
                const fertig = Math.min(stand.dateien || 0, gesamt);

                return gesamt === 0
                    ? 'Dateien werden gesichert …'
                    : 'Dateien: ' + fertig + ' von ' + gesamt + ' gesichert …';
            }
            case 'abschluss':
                return 'Backup wird abgeschlossen …';
            case 'verschluesseln': {
                const anteil = prozent(stand.verschluesselt, stand.zip_bytes);

                return anteil === null
                    ? 'Backup wird verschlüsselt …'
                    : 'Backup wird verschlüsselt … (' + anteil + ' %)';
            }
            default:
                return 'Backup wird erstellt …';
        }
    }

    /** Whether the chain needs another step after this answer. */
    function weiter(stand) {
        return !!stand && !stand.fertig;
    }

    function initBackups() {
        const formular = document.querySelector('#backup-erstellen');
        if (formular === null) {
            return;
        }

        const csrf = formular.dataset.csrf || '';
        const start = formular.querySelector('#backup-start');
        const fortsetzen = formular.querySelector('#backup-fortsetzen');
        const verlauf = document.querySelector('#backup-verlauf');
        const balken = document.querySelector('#backup-fortschritt');
        const status = document.querySelector('#backup-status');
        const fehler = document.querySelector('#backup-fehler');

        function zeigen(stand) {
            status.textContent = statusText(stand) || 'Backup wird erstellt …';
            const wert = fortschritt(stand);
            if (wert === null) {
                balken.removeAttribute('value');
            } else {
                balken.value = wert;
            }
        }

        async function senden(pfad) {
            const daten = new FormData();
            daten.append('_csrf', csrf);
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
                stand = await senden('/admin/backups/schritt');
                zeigen(stand);
                schritte++;
            }
            if (stand && stand.fertig) {
                // The new backup belongs in the list; the server set a flash.
                window.location.reload();
            }
        }

        async function ausfuehren(arbeit) {
            start.disabled = true;
            fortsetzen.disabled = true;
            verlauf.hidden = false;
            fehler.hidden = true;
            try {
                await arbeit();
            } catch (problem) {
                status.textContent = 'Das Backup ist unterbrochen.';
                fehler.textContent = problem.message + ' „Backup fortsetzen“ wiederholt den letzten Schritt.';
                fehler.hidden = false;
                start.hidden = true;
                fortsetzen.hidden = false;
            } finally {
                start.disabled = false;
                fortsetzen.disabled = false;
            }
        }

        formular.addEventListener('submit', (ereignis) => {
            ereignis.preventDefault();
            ausfuehren(async () => {
                const stand = await senden('/admin/backups');
                await laufe(stand);
            });
        });

        fortsetzen.addEventListener('click', () => {
            ausfuehren(() => laufe({ phase: '', fertig: false }));
        });
    }

    if (typeof document !== 'undefined') {
        initBackups();
    }

    // Node (tests/js) loads the same file for the pure helpers above; browsers
    // ignore this block because `module` does not exist there.
    if (typeof module === 'object' && module.exports) {
        module.exports = { statusText, fortschritt, weiter, prozent };
    }
})();
