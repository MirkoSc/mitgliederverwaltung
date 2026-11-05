'use strict';

/**
 * Fixed address suggestions for the browser of the screenshot script.
 *
 * The member form asks the application for suggestions while the street is
 * typed (POST /app/anschrift/vorschlaege, public/js/anschrift-vorschlaege.js),
 * and the application asks Photon. The pictures must not depend on that
 * service (no way out of the local docker environment, answers change), so a
 * recording that shows the list answers the request itself with made-up
 * addresses from the sample town. Only in the browser of this script and
 * only on the page of that recording; the application is unchanged.
 */

/** Pattern for Playwright's route(): the suggestions of the application. */
const ANSCHRIFT_ROUTE = '**/app/anschrift/vorschlaege';

/** Made-up addresses in the town of the sample data (tools/hilfe-beispieldaten.php). */
const VORSCHLAEGE = [
    { strasse: 'Lindenweg 3', plz: '12345', ort: 'Musterstadt', land: 'DE', text: 'Lindenweg 3, 12345 Musterstadt' },
    { strasse: 'Lindenweg 30', plz: '12345', ort: 'Musterstadt', land: 'DE', text: 'Lindenweg 30, 12345 Musterstadt' },
    { strasse: 'Lindenplatz 3', plz: '12347', ort: 'Musterdorf', land: 'DE', text: 'Lindenplatz 3, 12347 Musterdorf' },
];

/** The body the application would send: {"vorschlaege": [...]}. */
function antwort(vorschlaege = VORSCHLAEGE) {
    return JSON.stringify({ vorschlaege });
}

/** Answers the suggestion requests of the page with the fixed list. */
async function anschriftVorschlaegeErsetzen(seite, vorschlaege = VORSCHLAEGE) {
    await seite.route(ANSCHRIFT_ROUTE, (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        headers: { 'Cache-Control': 'no-store' },
        body: antwort(vorschlaege),
    }));
}

module.exports = { ANSCHRIFT_ROUTE, VORSCHLAEGE, antwort, anschriftVorschlaegeErsetzen };
