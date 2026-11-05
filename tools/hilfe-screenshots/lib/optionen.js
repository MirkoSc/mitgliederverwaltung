'use strict';

/** Command line of aufnehmen.js (start.sh passes everything through). */

const { gueltigerSlug } = require('./namen');

const STANDARD = {
    projekt: 'hilfe',
    pruefen: false,
    nur: null,
    ausgabe: null,
    anleitungenJson: null,
};

/**
 * @param {string[]} argumente without "node" and the script
 */
function optionenLesen(argumente) {
    const optionen = { ...STANDARD };
    for (let i = 0; i < argumente.length; i++) {
        const argument = argumente[i];
        const wert = () => {
            const naechster = argumente[++i];
            if (naechster === undefined || naechster.startsWith('--')) {
                throw new Error(`${argument} braucht einen Wert.`);
            }
            return naechster;
        };
        switch (argument) {
            case '--pruefen':
                optionen.pruefen = true;
                break;
            case '--projekt':
                optionen.projekt = wert();
                break;
            case '--nur':
                optionen.nur = wert();
                if (!gueltigerSlug(optionen.nur)) {
                    throw new Error(`--nur: "${optionen.nur}" ist kein gültiger Slug.`);
                }
                break;
            case '--ausgabe':
                optionen.ausgabe = wert();
                break;
            case '--anleitungen-json':
                optionen.anleitungenJson = wert();
                break;
            default:
                throw new Error(`Unbekannte Option "${argument}".`);
        }
    }
    return optionen;
}

module.exports = { optionenLesen };
