// Drives the import step chain of the settings section Import (issue #32,
// docs/spec/10-import.md section 1): one request starts the chain, then one
// request per step (about 100 lines) until the server reports the import
// done. No single request runs long (CLAUDE.md section 1). Patterned on
// backups.js.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token and the step
// URL come from data-* attributes on the form.

/** Upper bound of steps, so a server that never says "fertig" cannot loop forever. */
const MAX_SCHRITTE = 100000;

/** Share of a total as whole percent, never above 100; null without a total. */
function prozent(teil, ganz) {
    if (!ganz || ganz <= 0) {
        return null;
    }

    return Math.min(100, Math.round((100 * (teil || 0)) / ganz));
}

/** How far the chain is, in percent; null where the server cannot know yet. */
function fortschritt(stand) {
    if (!stand) {
        return null;
    }
    if (stand.fertig) {
        return 100;
    }

    return prozent(stand.offset, stand.gesamt);
}

/** The status line for a state answered by the server. */
function statusText(stand) {
    if (!stand) {
        return '';
    }
    if (stand.fertig) {
        return 'Der Import ist abgeschlossen.';
    }
    const gesamt = stand.gesamt || 0;
    if (gesamt === 0) {
        return 'Import wird gestartet …';
    }
    const fertig = Math.min(stand.offset || 0, gesamt);

    return (
        'Importiert: ' +
        fertig.toLocaleString('de-DE') +
        ' von ' +
        gesamt.toLocaleString('de-DE') +
        ' Mitgliedern …'
    );
}

/** Whether the chain needs another step after this answer. */
function weiter(stand) {
    return !!stand && !stand.fertig;
}

function initImport() {
    const formular = document.querySelector('#import-starten') || document.querySelector('#import-weiter');
    if (formular === null) {
        return;
    }

    const csrf = formular.dataset.csrf || '';
    const schrittPfad = formular.dataset.schritt || '/admin/import/schritt';
    const start = document.querySelector('#import-start');
    const fortsetzen = document.querySelector('#import-fortsetzen');
    const verlauf = document.querySelector('#import-verlauf');
    const balken = document.querySelector('#import-fortschritt');
    const status = document.querySelector('#import-status');
    const fehler = document.querySelector('#import-fehler');
    const knoepfe = [start, fortsetzen].filter((knopf) => knopf !== null);

    function zeigen(stand) {
        status.textContent = statusText(stand) || 'Import läuft …';
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
            stand = await senden(schrittPfad);
            zeigen(stand);
            schritte++;
        }
        if (stand && stand.fertig) {
            // The members belong on the page; the server set a flash.
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
            status.textContent = 'Der Import ist unterbrochen.';
            fehler.textContent = problem.message + ' „Import fortsetzen“ wiederholt den letzten Schritt.';
            fehler.hidden = false;
            if (start !== null) {
                start.hidden = true;
            }
            if (fortsetzen !== null) {
                fortsetzen.hidden = false;
            }
        } finally {
            knoepfe.forEach((knopf) => {
                knopf.disabled = false;
            });
        }
    }

    if (start !== null) {
        formular.addEventListener('submit', (ereignis) => {
            ereignis.preventDefault();
            ausfuehren(async () => {
                const stand = await senden(formular.getAttribute('action'));
                await laufe(stand);
            });
        });
    }

    if (fortsetzen !== null) {
        fortsetzen.addEventListener('click', () => {
            ausfuehren(() => laufe({ phase: 'import', fertig: false, offset: 0, gesamt: 0 }));
        });
    }
}

if (typeof document !== 'undefined') {
    initImport();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { statusText, fortschritt, weiter, prozent };
}
