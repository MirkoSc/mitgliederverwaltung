'use strict';

/**
 * Names and paths of the help pictures (docs/spec/12-hilfe.md sections 2 and
 * 6): public/img/hilfe/<slug>/<NN>-<modus>.png, NN = number of the step,
 * two digits from 01.
 */

const path = require('node:path');

const MODI = ['normal', 'barrierefrei'];

const SLUG = /^[a-z0-9-]+$/;

function gueltigerSlug(slug) {
    return typeof slug === 'string' && SLUG.test(slug);
}

function gueltigerModus(modus) {
    return MODI.includes(modus);
}

/** "01-normal.png" for step 1. */
function bildDatei(nr, modus) {
    if (!Number.isInteger(nr) || nr < 1 || nr > 99) {
        throw new Error(`Schrittnummer ${nr} ist ungültig (ganze Zahl von 1 bis 99).`);
    }
    if (!gueltigerModus(modus)) {
        throw new Error(`Unbekannter Modus "${modus}".`);
    }
    return `${String(nr).padStart(2, '0')}-${modus}.png`;
}

/** Where a picture goes below the output directory. */
function bildPfad(ausgabe, slug, nr, modus) {
    if (!gueltigerSlug(slug)) {
        throw new Error(`Ungültiger Slug "${slug}" (nur a-z, 0-9 und Bindestrich).`);
    }
    return path.join(ausgabe, slug, bildDatei(nr, modus));
}

/**
 * Key of the demo account in konten.json: "normal" / "barrierefrei" by
 * default, "<zugang>-normal" when a recording asks for another access
 * (`export const zugang = 'admin'`).
 */
function kontoSchluessel(zugang, modus) {
    if (!gueltigerModus(modus)) {
        throw new Error(`Unbekannter Modus "${modus}".`);
    }
    return zugang ? `${zugang}-${modus}` : modus;
}

module.exports = { MODI, gueltigerSlug, gueltigerModus, bildDatei, bildPfad, kontoSchluessel };
