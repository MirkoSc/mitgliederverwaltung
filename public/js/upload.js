// Uploads a file in 2 MiB chunks (docs/spec/03-datenhaltung.md section 3):
// one short request per chunk, so nothing runs into the hoster's request time
// limit and the upload limit per request stops mattering (CLAUDE.md section 1).
//
// The browser side of App\Http\ChunkUpload. Its own file rather than an
// inline script: the CSP is script-src 'self' without 'unsafe-inline'
// (CLAUDE.md section 4), and all requests go to the same origin.
//
// A page that takes files loads this file, then calls dateiHochladen() with
// the route prefix its route file registered ChunkUpload under.

/** Chunk size of the server; /api/upload answers with the one that counts. */
const CHUNK_BYTES = 2 * 1024 * 1024;

/** How many chunks a file of this size is cut into. */
function chunkAnzahl(groesse, chunkBytes) {
    if (!(groesse > 0) || !(chunkBytes > 0)) {
        return 0;
    }

    return Math.ceil(groesse / chunkBytes);
}

/**
 * The pieces of the file, in order: index and the byte range for
 * File.slice(). The last piece is shorter, all others are full.
 */
function chunkGrenzen(groesse, chunkBytes) {
    const grenzen = [];
    const anzahl = chunkAnzahl(groesse, chunkBytes);

    for (let index = 0; index < anzahl; index++) {
        const start = index * chunkBytes;
        grenzen.push({ index: index, start: start, ende: Math.min(start + chunkBytes, groesse) });
    }

    return grenzen;
}

/** Progress in whole percent, never above 100, 100 for an empty file. */
function fortschrittProzent(gesendet, gesamt) {
    if (!(gesamt > 0)) {
        return 100;
    }

    return Math.min(100, Math.floor((gesendet / gesamt) * 100));
}

/**
 * What went wrong, in the server's words when it said anything - it answers
 * German sentences meant for the person in front of the browser.
 */
function fehlertext(antwort, daten) {
    if (daten && daten.fehler) {
        return daten.fehler;
    }

    return 'HTTP ' + (antwort ? antwort.status : '?');
}

/**
 * Uploads one file and returns the server's answer to the closing request
 * (what the area's finish closure returned, e.g. { blob_id }).
 *
 * `fetch` is a parameter so that the tests can drive the whole sequence
 * without a network and without a DOM; in the browser the default is the real
 * one. `datei` is a File or Blob: .size, .name and .slice() are all that is
 * used of it. `basis` is the route prefix the area registered ChunkUpload
 * under and is required. `headers` adds further headers to every request.
 */
async function dateiHochladen(datei, optionen) {
    const einstellungen = optionen || {};
    const csrf = einstellungen.csrf || '';
    const basis = einstellungen.basis || '';
    const holen = einstellungen.fetch || (typeof fetch === 'function' ? fetch : null);
    const melden = einstellungen.onFortschritt || function () {};
    const zusatzHeader = einstellungen.headers || {};

    if (basis === '') {
        throw new Error('Kein Upload-Pfad angegeben.');
    }
    if (holen === null) {
        throw new Error('Kein fetch verfügbar.');
    }

    const senden = async function (pfad, optionenDerAnfrage) {
        const anfrage = Object.assign({ method: 'POST' }, optionenDerAnfrage);
        anfrage.headers = Object.assign({ 'X-CSRF-Token': csrf }, zusatzHeader, anfrage.headers || {});

        const antwort = await holen(pfad, anfrage);
        const daten = await antwort.json();
        if (!antwort.ok || daten.fehler) {
            throw new Error(fehlertext(antwort, daten));
        }

        return daten;
    };

    const eroeffnet = await senden(basis, {
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ groesse: datei.size }),
    });

    try {
        // Strictly one after the other: the chunks may arrive in any order,
        // but sending them in parallel would put several megabytes on a
        // mobile connection at once and make the progress meaningless.
        let gesendet = 0;
        const grenzen = chunkGrenzen(datei.size, eroeffnet.chunk_bytes || CHUNK_BYTES);
        for (const grenze of grenzen) {
            await senden(basis + '/' + eroeffnet.id + '/chunk/' + grenze.index, {
                headers: { 'Content-Type': 'application/octet-stream' },
                body: datei.slice(grenze.start, grenze.ende),
            });

            gesendet += grenze.ende - grenze.start;
            melden(fortschrittProzent(gesendet, datei.size));
        }

        return await senden(basis + '/' + eroeffnet.id + '/finish', {
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: datei.name || '' }),
        });
    } catch (problem) {
        // Give the chunks back right away instead of leaving them for the
        // cron - the person may pick another file straight away.
        try {
            await senden(basis + '/' + eroeffnet.id + '/abort', {});
        } catch (ignoriert) {
            // The upload is lost either way; the original error is the one
            // worth reporting.
        }

        throw problem;
    }
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { CHUNK_BYTES, chunkAnzahl, chunkGrenzen, fortschrittProzent, fehlertext, dateiHochladen };
}
