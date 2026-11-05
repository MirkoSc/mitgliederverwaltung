/*
 * IBAN and BIC in the member form (docs/spec/07-mitglieder.md section 1):
 * checks the IBAN while typing and when leaving the field, marks a wrong
 * one red (aria-invalid + the text below it), fills in the BIC of a German
 * IBAN when the BIC field is empty and shows the bank's name below the BIC;
 * a BIC of the wrong shape or one that does not fit the IBAN is marked red,
 * too. Nothing is ever blocked - saving stays possible, the server says the
 * same after saving.
 *
 * The check digits are computed here; only the Bankleitzahl (digits 5-12 of
 * a valid German IBAN) goes to the server (GET data-bank-adresse/{blz}),
 * never the IBAN. Delegated on the document, because htmx swaps the member
 * in and out. The rules are plain functions, tested with node --test
 * (tests/js/bankverbindung.test.js); the lengths are those of
 * App\Support\Iban.
 */
(function () {
    'use strict';

    var LAENGEN = {
        AD: 24, AT: 20, BE: 16, BG: 22, CH: 21, CY: 28,
        CZ: 24, DE: 22, DK: 18, EE: 20, ES: 24, FI: 18,
        FR: 27, GB: 22, GI: 23, GR: 27, HR: 21, HU: 28,
        IE: 22, IS: 26, IT: 27, LI: 21, LT: 20, LU: 20,
        LV: 21, MC: 27, MT: 31, NL: 18, NO: 15, PL: 28,
        PT: 25, RO: 24, SE: 24, SI: 19, SK: 24, SM: 27,
        VA: 22,
    };

    // The three texts are the same as in PHP: Iban::FEHLER_PRUEFZIFFER and
    // Banken::bicFehler() - keep both in step.
    var TEXT_IBAN = 'Die Prüfziffer passt nicht. Bitte die IBAN vom Kontoauszug abschreiben – gespeichert wird sie trotzdem.';
    var TEXT_BIC_FORM = 'Die BIC hat nicht die richtige Form (8 oder 11 Zeichen, z. B. COBADEFFXXX).';
    var TEXT_BIC_PASST_NICHT = 'Die BIC passt nicht zur IBAN – zur Bankleitzahl gehört ';

    /** Upper case, without spaces and dashes. */
    function ibanNormalisieren(iban) {
        return String(iban).replace(/[\s-]+/g, '').toUpperCase();
    }

    /** Format, country length and mod-97 check digits, as App\Support\Iban::istGueltig(). */
    function ibanGueltig(iban) {
        var wert = ibanNormalisieren(iban);
        if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(wert)) {
            return false;
        }
        var laenge = LAENGEN[wert.slice(0, 2)];
        if (laenge !== undefined && wert.length !== laenge) {
            return false;
        }
        var umgestellt = wert.slice(4) + wert.slice(0, 4);
        var rest = 0;
        for (var i = 0; i < umgestellt.length; i++) {
            var zeichen = umgestellt.charAt(i);
            var ziffern = /[A-Z]/.test(zeichen) ? String(zeichen.charCodeAt(0) - 55) : zeichen;
            for (var j = 0; j < ziffern.length; j++) {
                rest = (rest * 10 + Number(ziffern.charAt(j))) % 97;
            }
        }
        return rest === 1;
    }

    /**
     * 'leer', 'unvollstaendig' (still being typed: fits so far but is too
     * short), 'gueltig' or 'ungueltig'.
     */
    function ibanStatus(iban) {
        var wert = ibanNormalisieren(iban);
        if (wert === '') {
            return 'leer';
        }
        if (ibanGueltig(wert)) {
            return 'gueltig';
        }
        var laenge = LAENGEN[wert.slice(0, 2)];
        var bisher = /^[A-Z]{0,2}$/.test(wert) || /^[A-Z]{2}\d{0,2}$/.test(wert) || /^[A-Z]{2}\d{2}[A-Z0-9]*$/.test(wert);
        if (bisher && wert.length < (laenge !== undefined ? laenge : 15)) {
            return 'unvollstaendig';
        }
        return 'ungueltig';
    }

    /** The Bankleitzahl of a valid German IBAN, otherwise null. */
    function blz(iban) {
        var wert = ibanNormalisieren(iban);
        return wert.slice(0, 2) === 'DE' && ibanGueltig(wert) ? wert.slice(4, 12) : null;
    }

    function bicNormalisieren(bic) {
        return String(bic).replace(/\s+/g, '').toUpperCase();
    }

    /** 8 or 11 characters: bank, country, location, optionally the branch. */
    function bicGueltig(bic) {
        return /^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(bicNormalisieren(bic));
    }

    /** "COBADEFF" and "COBADEFFXXX" are the same office. */
    function bicGleich(a, b) {
        var lang = function (bic) {
            var wert = bicNormalisieren(bic);
            return wert.length === 8 ? wert + 'XXX' : wert;
        };
        return lang(a) === lang(b);
    }

    /**
     * The error text for the BIC field, or '' when there is none.
     * @param {string} bic what is typed
     * @param {?string} bankBic the BIC of the IBAN's bank, null if unknown
     */
    function bicFehler(bic, bankBic) {
        if (bicNormalisieren(bic) === '') {
            return '';
        }
        if (!bicGueltig(bic)) {
            return TEXT_BIC_FORM;
        }
        if (bankBic && !bicGleich(bic, bankBic)) {
            return TEXT_BIC_PASST_NICHT + bankBic + '.';
        }
        return '';
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = {
            ibanNormalisieren: ibanNormalisieren,
            ibanGueltig: ibanGueltig,
            ibanStatus: ibanStatus,
            blz: blz,
            bicNormalisieren: bicNormalisieren,
            bicGueltig: bicGueltig,
            bicGleich: bicGleich,
            bicFehler: bicFehler,
        };
    }

    if (typeof document === 'undefined' || typeof fetch === 'undefined') {
        return;
    }

    /** Answers of the server by BLZ, for this page only (no storage). */
    var banken = {};

    /** Shows or clears the error below a field. */
    function melden(feld, hinweis, text) {
        if (text) {
            feld.setAttribute('aria-invalid', 'true');
        } else {
            feld.removeAttribute('aria-invalid');
        }
        if (hinweis !== null && hinweis.textContent !== text) {
            hinweis.textContent = text;
        }
        if (hinweis !== null) {
            hinweis.hidden = !text;
        }
    }

    function bankFuer(adresse, kennung) {
        if (!banken[kennung]) {
            banken[kennung] = fetch(adresse + '/' + kennung, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            }).then(function (antwort) {
                return antwort.ok ? antwort.json() : { bank: null, bic: null };
            }).catch(function () {
                delete banken[kennung];
                return { bank: null, bic: null };
            });
        }
        return banken[kennung];
    }

    function teile() {
        var iban = document.getElementById('m-iban');
        var bic = document.getElementById('m-bic');
        if (!(iban instanceof HTMLInputElement) || !(bic instanceof HTMLInputElement)) {
            return null;
        }
        return {
            iban: iban,
            bic: bic,
            ibanHinweis: document.getElementById('m-iban-hinweis'),
            bicHinweis: document.getElementById('m-bic-hinweis'),
            bankZeile: document.getElementById('m-bic-bank'),
        };
    }

    /** The BIC of the IBAN's bank as last answered (null: unknown). */
    function bankBic(t) {
        return t.bic.getAttribute('data-bank-bic') || null;
    }

    function bicPruefen(t) {
        melden(t.bic, t.bicHinweis, bicFehler(t.bic.value, bankBic(t)));
    }

    function bankZeigen(t, bank) {
        if (t.bankZeile !== null) {
            t.bankZeile.textContent = bank && bank.bank ? 'Bank: ' + bank.bank : '';
            t.bankZeile.hidden = !(bank && bank.bank);
        }
        if (bank && bank.bic) {
            t.bic.setAttribute('data-bank-bic', bank.bic);
        } else {
            t.bic.removeAttribute('data-bank-bic');
        }
    }

    /** @param {boolean} verlassen the field was left: an incomplete IBAN counts as wrong */
    function ibanPruefen(t, verlassen) {
        var status = ibanStatus(t.iban.value);
        var falsch = status === 'ungueltig' || (verlassen && status === 'unvollstaendig');
        melden(t.iban, t.ibanHinweis, falsch ? TEXT_IBAN : '');

        var kennung = blz(t.iban.value);
        if (kennung === null) {
            bankZeigen(t, null);
            bicPruefen(t);
            return;
        }
        var adresse = t.iban.getAttribute('data-bank-adresse');
        if (!adresse) {
            return;
        }
        bankFuer(adresse, kennung).then(function (bank) {
            if (blz(t.iban.value) !== kennung) {
                return;
            }
            bankZeigen(t, bank);
            // Fill in an empty BIC, or replace one filled in here for an
            // earlier IBAN - never one somebody typed.
            var vorher = t.bic.getAttribute('data-ergaenzt');
            var leer = bicNormalisieren(t.bic.value) === '';
            if (bank.bic && (leer || (vorher !== null && bicNormalisieren(t.bic.value) === vorher))) {
                t.bic.value = bank.bic;
                t.bic.setAttribute('data-ergaenzt', bank.bic);
            }
            bicPruefen(t);
        });
    }

    function behandeln(ereignis) {
        var ziel = ereignis.target;
        if (!(ziel instanceof HTMLInputElement) || (ziel.id !== 'm-iban' && ziel.id !== 'm-bic')) {
            return;
        }
        var t = teile();
        if (t === null) {
            return;
        }
        if (ziel === t.iban) {
            ibanPruefen(t, ereignis.type === 'change');
        } else {
            if (ereignis.type === 'input') {
                t.bic.removeAttribute('data-ergaenzt');
            }
            bicPruefen(t);
        }
    }

    document.addEventListener('input', behandeln);
    document.addEventListener('change', behandeln);
    // A form that comes in with a valid German IBAN: look up its bank so a
    // BIC typed later is compared with it.
    function beimLaden() {
        var t = teile();
        if (t !== null && blz(t.iban.value) !== null) {
            ibanPruefen(t, false);
        }
    }
    document.addEventListener('htmx:afterSettle', beimLaden);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', beimLaden);
    } else {
        beimLaden();
    }
}());
