'use strict';

/**
 * Reconciliation of the guides with the recordings (docs/spec/12-hilfe.md
 * section 6): the numbers a recording passes to foto() must be exactly the
 * steps of the guide that have a `bild`. The guides come from
 * tools/hilfe-anleitungen.php as JSON, the same data the application reads.
 */

const { gueltigerSlug } = require('./namen');

/**
 * @param {string} text JSON of tools/hilfe-anleitungen.php: a list of
 *        { slug, titel, recht, bilder: [step numbers with a picture] }
 * @returns {Map<string, {slug: string, titel: string, recht: string|null, bilder: number[]}>}
 */
function anleitungenLesen(text) {
    let daten;
    try {
        daten = JSON.parse(text);
    } catch {
        throw new Error('Die Ausgabe von tools/hilfe-anleitungen.php ist kein gültiges JSON.');
    }
    if (!Array.isArray(daten)) {
        throw new Error('tools/hilfe-anleitungen.php muss eine Liste von Anleitungen ausgeben.');
    }
    const karte = new Map();
    for (const eintrag of daten) {
        if (!eintrag || !gueltigerSlug(eintrag.slug)) {
            throw new Error('Eine Anleitung hat keinen gültigen "slug".');
        }
        if (!Array.isArray(eintrag.bilder) || !eintrag.bilder.every((n) => Number.isInteger(n) && n >= 1)) {
            throw new Error(`Anleitung "${eintrag.slug}": "bilder" muss die Nummern der Schritte mit Bild enthalten (12 §2).`);
        }
        if (karte.has(eintrag.slug)) {
            throw new Error(`Anleitung "${eintrag.slug}" kommt doppelt vor.`);
        }
        karte.set(eintrag.slug, {
            slug: eintrag.slug,
            titel: String(eintrag.titel ?? ''),
            recht: eintrag.recht ?? null,
            bilder: [...eintrag.bilder].sort((a, b) => a - b),
        });
    }
    return karte;
}

/**
 * One guide against one recording.
 *
 * @param {number[]} erwartet step numbers with `bild`
 * @param {number[]} aufgenommen numbers passed to foto()
 * @returns {{ok: boolean, fehlend: number[], uebrig: number[], doppelt: number[], meldung: string}}
 */
function abgleich(erwartet, aufgenommen) {
    const gesehen = new Set();
    const doppelt = [];
    for (const nr of aufgenommen) {
        if (gesehen.has(nr) && !doppelt.includes(nr)) {
            doppelt.push(nr);
        }
        gesehen.add(nr);
    }
    const fehlend = erwartet.filter((nr) => !gesehen.has(nr));
    const uebrig = [...gesehen].filter((nr) => !erwartet.includes(nr)).sort((a, b) => a - b);
    const teile = [];
    if (fehlend.length > 0) {
        teile.push(`kein Foto für Schritt ${fehlend.join(', ')} (die Anleitung hat dort ein "bild")`);
    }
    if (uebrig.length > 0) {
        teile.push(`Foto für Schritt ${uebrig.join(', ')}, aber die Anleitung hat dort kein "bild"`);
    }
    if (doppelt.length > 0) {
        teile.push(`Schritt ${doppelt.join(', ')} mehrfach aufgenommen`);
    }

    return { ok: teile.length === 0, fehlend, uebrig, doppelt, meldung: teile.join('; ') };
}

/**
 * Guides and recording files against each other, before anything runs:
 * a guide with pictures needs a recording; a recording needs a guide
 * (probes excepted).
 *
 * @param {Map<string, {bilder: number[]}>} anleitungen
 * @param {string[]} aufnahmen slugs of the files in anleitungen/
 * @param {string[]} proben slugs of recordings that are only probes
 * @returns {string[]} problems, empty when everything fits
 */
function dateienAbgleichen(anleitungen, aufnahmen, proben = []) {
    const probleme = [];
    for (const [slug, anleitung] of anleitungen) {
        if (anleitung.bilder.length > 0 && !aufnahmen.includes(slug)) {
            probleme.push(`Anleitung "${slug}" hat Bilder, aber es gibt keine Aufnahme anleitungen/${slug}.mjs.`);
        }
    }
    for (const slug of aufnahmen) {
        if (!anleitungen.has(slug) && !proben.includes(slug)) {
            probleme.push(`Aufnahme anleitungen/${slug}.mjs hat keine Anleitung app/data/hilfe/NN-${slug}.php.`);
        }
    }
    return probleme;
}

module.exports = { anleitungenLesen, abgleich, dateienAbgleichen };
