'use strict';

/**
 * A neutral map tile for the browser of the screenshot script.
 *
 * The small map at an opened member loads its tiles through the
 * application (/app/karte/kachel/...), which fetches them from the tile
 * server. The pictures must not depend on that: the local docker
 * environment may have no way out, the map data change over time, and the
 * pictures are taken again and again. So the script answers tile requests
 * itself with one plain tile in the colour of land on the map - the map
 * stays a calm background, the marker and the text carry the meaning.
 * Only in the browser of this script; the application is unchanged.
 */

const zlib = require('node:zlib');

/** Pattern for Playwright's route(): every tile of the application. */
const KACHEL_ROUTE = '**/app/karte/kachel/**';

const GROESSE = 256;

/** Land colour of the usual map style, light and calm. */
const FARBE = [0xf2, 0xef, 0xe9];

function abschnitt(typ, daten) {
    const laenge = Buffer.alloc(4);
    laenge.writeUInt32BE(daten.length);
    const typUndDaten = Buffer.concat([Buffer.from(typ, 'ascii'), daten]);
    const pruefsumme = Buffer.alloc(4);
    pruefsumme.writeUInt32BE(zlib.crc32(typUndDaten));

    return Buffer.concat([laenge, typUndDaten, pruefsumme]);
}

/** A PNG of one colour (RGB, 8 bit), size × size pixels. */
function einfarbigesPng(groesse = GROESSE, farbe = FARBE) {
    const kopf = Buffer.alloc(13);
    kopf.writeUInt32BE(groesse, 0);
    kopf.writeUInt32BE(groesse, 4);
    kopf[8] = 8; // bit depth
    kopf[9] = 2; // colour type RGB
    const zeile = Buffer.alloc(1 + groesse * 3); // filter byte 0, then pixels
    for (let x = 0; x < groesse; x++) {
        zeile[1 + x * 3] = farbe[0];
        zeile[2 + x * 3] = farbe[1];
        zeile[3 + x * 3] = farbe[2];
    }
    const pixel = Buffer.concat(Array.from({ length: groesse }, () => zeile));

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        abschnitt('IHDR', kopf),
        abschnitt('IDAT', zlib.deflateSync(pixel)),
        abschnitt('IEND', Buffer.alloc(0)),
    ]);
}

const KACHEL = einfarbigesPng();

/** Answers every tile request of the context with the neutral tile. */
async function kachelnErsetzen(kontext) {
    await kontext.route(KACHEL_ROUTE, (route) => route.fulfill({
        status: 200,
        contentType: 'image/png',
        headers: { 'Cache-Control': 'no-store' },
        body: KACHEL,
    }));
}

module.exports = { KACHEL, KACHEL_ROUTE, einfarbigesPng, kachelnErsetzen };
