// The installer's two bits of script (docs/spec/04-installation-und-update.md
// sections 2 and 6): the print button of the Sicherungsschlüssel, and the
// restore step chain - one short request per step until the server says the
// backup is in and config.php is written. No single request runs long
// (CLAUDE.md section 1).
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute.

/** Upper bound of steps, so a server that never says "fertig" cannot loop forever. */
const MAX_SCHRITTE = 100000;

/** What the phases of App\Service\Backup\Wiederherstellung are called on the page. */
const PHASEN = {
    entschluesseln: 'Backup wird entschlüsselt …',
    auspacken: 'Backup wird geöffnet …',
    dump: 'Datenbank wird eingespielt …',
    dateien: 'Dateien werden eingespielt …',
    migrationen: 'Datenbank wird auf den neuen Stand gebracht …',
};

/** The status line for an answer of POST /install/wiederherstellen/schritt. */
function statusText(stand) {
    if (!stand) {
        return 'Backup wird eingespielt …';
    }
    if (stand.fertig) {
        return 'Das Backup ist eingespielt.';
    }
    const text = PHASEN[stand.phase] || 'Backup wird eingespielt …';

    return typeof stand.fortschritt === 'number' ? text + ' (' + stand.fortschritt + ' %)' : text;
}

/** Whether the chain needs another step after this answer. */
function weiter(stand) {
    return !stand || !stand.fertig;
}

/** The note on files of the backup that were not taken over, or ''. */
function abgelehntText(anzahl) {
    if (!anzahl || anzahl <= 0) {
        return '';
    }

    return anzahl === 1
        ? 'Eine Datei im Backup hatte einen unbekannten Namen und wurde nicht übernommen.'
        : anzahl + ' Dateien im Backup hatten einen unbekannten Namen und wurden nicht übernommen.';
}

function initDrucken() {
    const knopf = document.querySelector('[data-drucken]');
    if (knopf === null) {
        return;
    }
    knopf.hidden = false;
    knopf.addEventListener('click', () => window.print());
}

function initWiederherstellung() {
    const bereich = document.querySelector('#restore');
    if (bereich === null) {
        return;
    }

    const csrf = bereich.dataset.csrf || '';
    const balken = document.querySelector('#restore-fortschritt');
    const status = document.querySelector('#restore-status');
    const fehler = document.querySelector('#restore-fehler');
    const wiederholen = document.querySelector('#restore-wiederholen');

    function zeigen(stand) {
        status.textContent = statusText(stand);
        if (stand && typeof stand.fortschritt === 'number') {
            balken.value = stand.fortschritt;
            balken.textContent = stand.fortschritt + ' %';
        }
    }

    async function schritt() {
        const daten = new FormData();
        daten.append('_csrf', csrf);
        const antwort = await fetch('/install/wiederherstellen/schritt', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
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

    function fertig(stand) {
        bereich.hidden = true;
        document.querySelector('#install-cron-url').textContent = stand.cron_url || '';
        const abgelehnt = document.querySelector('#restore-abgelehnt');
        abgelehnt.textContent = abgelehntText(stand.abgelehnt);
        abgelehnt.hidden = abgelehnt.textContent === '';
        document.querySelector('#restore-setup').hidden = !stand.setup_uebrig;
        for (const neu of document.querySelectorAll('.install-neu')) {
            neu.hidden = true;
        }
        document.querySelector('#restore-fertig').hidden = false;
    }

    async function laufe() {
        wiederholen.hidden = true;
        fehler.hidden = true;
        let stand = null;
        let schritte = 0;
        try {
            while (weiter(stand) && schritte < MAX_SCHRITTE) {
                stand = await schritt();
                zeigen(stand);
                schritte++;
            }
        } catch (problem) {
            status.textContent = 'Das Einspielen ist unterbrochen.';
            fehler.textContent = problem.message + ' „Schritt wiederholen“ macht dort weiter.';
            fehler.hidden = false;
            wiederholen.hidden = false;

            return;
        }
        if (stand && stand.fertig) {
            fertig(stand);
        }
    }

    wiederholen.addEventListener('click', laufe);
    laufe();
}

if (typeof document !== 'undefined') {
    initDrucken();
    initWiederherstellung();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { statusText, weiter, abgelehntText };
}
