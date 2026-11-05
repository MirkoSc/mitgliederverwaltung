/*
 * Address suggestions under the street field of the member form (issue
 * #127): a combobox after the WAI-ARIA pattern (role=combobox, listbox,
 * aria-activedescendant; arrow keys, Enter, Escape). From three characters
 * and a pause of DELAY_MS in typing, the text goes - with postcode and town
 * as context - by POST to this installation (App\App\AnschriftController),
 * which asks Photon; the browser never talks to a foreign host.
 *
 * Choosing a suggestion fills street, postcode, town and country - each
 * only when the suggestion has a value for it - and fires `input` and
 * `change` on every filled field. The country: the element marked
 * [data-land-iso] in the form, else the field named "land"; a <select>
 * gets the ISO code when it has that option, any other field the ISO code
 * itself. Afterwards the form receives the event "anschrift-vorschlag"
 * (bubbles, detail = {strasse, plz, ort, land}) for whatever shows the
 * country by name.
 *
 * Without the script the form is the plain form. Nothing is stored in the
 * browser. Delegated on the document and set up again after every htmx
 * swap, because htmx swaps the member in and out.
 */
(function () {
    'use strict';

    var MIN_ZEICHEN = 3;
    var DELAY_MS = 300;
    var FELDER = ['strasse', 'plz', 'ort'];

    /** Whether a typed text is long enough to ask. */
    function sollSuchen(text) {
        return typeof text === 'string' && text.trim().length >= MIN_ZEICHEN;
    }

    /**
     * The option to mark after an arrow key: down from "none" (-1) is the
     * first, up from "none" the last; both wrap around. -1 without options.
     */
    function naechsterIndex(aktuell, anzahl, taste) {
        if (anzahl <= 0) {
            return -1;
        }
        if (taste === 'ArrowDown') {
            return aktuell < 0 || aktuell >= anzahl - 1 ? 0 : aktuell + 1;
        }
        if (taste === 'ArrowUp') {
            return aktuell <= 0 ? anzahl - 1 : aktuell - 1;
        }
        if (taste === 'Home') {
            return 0;
        }
        if (taste === 'End') {
            return anzahl - 1;
        }

        return aktuell;
    }

    /** The fields a suggestion fills: name -> value, only non-empty values. */
    function felderFuer(vorschlag) {
        var ergebnis = {};
        if (vorschlag === null || typeof vorschlag !== 'object') {
            return ergebnis;
        }
        FELDER.concat(['land']).forEach(function (name) {
            var wert = vorschlag[name];
            if (typeof wert === 'string' && wert.trim() !== '') {
                ergebnis[name] = wert.trim();
            }
        });

        return ergebnis;
    }

    /** The suggestions from the server's answer, [] for anything unexpected. */
    function vorschlaegeAus(daten) {
        if (daten === null || typeof daten !== 'object' || !Array.isArray(daten.vorschlaege)) {
            return [];
        }

        return daten.vorschlaege.filter(function (v) {
            return v !== null && typeof v === 'object' && typeof v.text === 'string' && v.text !== '';
        });
    }

    /** What the screen reader hears when the list changes. */
    function ansage(anzahl) {
        if (anzahl === 0) {
            return 'Keine Anschriftvorschläge.';
        }

        return (anzahl === 1 ? 'Ein Anschriftvorschlag' : anzahl + ' Anschriftvorschläge')
            + '. Mit den Pfeiltasten auswählen, mit Eingabe übernehmen.';
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = {
            sollSuchen: sollSuchen,
            naechsterIndex: naechsterIndex,
            felderFuer: felderFuer,
            vorschlaegeAus: vorschlaegeAus,
            ansage: ansage,
        };
    }

    if (typeof document === 'undefined' || typeof document.addEventListener !== 'function' || typeof window.fetch !== 'function') {
        return;
    }

    var SELEKTOR = 'input[data-anschrift-vorschlaege]';
    // State per field, keyed by the element itself.
    var zustaende = new WeakMap();

    function zustand(feld) {
        var z = zustaende.get(feld);
        if (z !== undefined) {
            return z;
        }
        var feldBox = feld.closest('.feld') || feld.parentElement;
        var id = (feld.id || 'anschrift') + '-vorschlaege';

        var liste = document.createElement('ul');
        liste.id = id;
        liste.className = 'anschrift-vorschlaege';
        liste.setAttribute('role', 'listbox');
        liste.setAttribute('aria-label', 'Anschriftvorschläge');
        liste.hidden = true;

        var status = document.createElement('p');
        status.className = 'nur-lesehilfe';
        status.setAttribute('role', 'status');

        feldBox.classList.add('anschrift-combobox');
        feld.insertAdjacentElement('afterend', liste);
        liste.insertAdjacentElement('afterend', status);

        feld.setAttribute('role', 'combobox');
        feld.setAttribute('aria-autocomplete', 'list');
        feld.setAttribute('aria-expanded', 'false');
        feld.setAttribute('aria-controls', id);

        z = { liste: liste, status: status, vorschlaege: [], aktiv: -1, uhr: null, abbruch: null, nummer: 0, still: false };
        zustaende.set(feld, z);

        return z;
    }

    function einrichten(wurzel) {
        if (wurzel === null || typeof wurzel.querySelectorAll !== 'function') {
            return;
        }
        if (typeof wurzel.matches === 'function' && wurzel.matches(SELEKTOR)) {
            zustand(wurzel);
        }
        wurzel.querySelectorAll(SELEKTOR).forEach(zustand);
    }

    function schliessen(feld, z) {
        z.liste.hidden = true;
        z.aktiv = -1;
        feld.setAttribute('aria-expanded', 'false');
        feld.removeAttribute('aria-activedescendant');
    }

    function markieren(feld, z, index) {
        z.aktiv = index;
        Array.prototype.forEach.call(z.liste.children, function (option, i) {
            option.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });
        var option = index >= 0 ? z.liste.children[index] : null;
        if (option) {
            feld.setAttribute('aria-activedescendant', option.id);
            if (typeof option.scrollIntoView === 'function') {
                option.scrollIntoView({ block: 'nearest' });
            }
        } else {
            feld.removeAttribute('aria-activedescendant');
        }
    }

    function zeigen(feld, z, vorschlaege) {
        z.vorschlaege = vorschlaege;
        z.liste.replaceChildren();
        vorschlaege.forEach(function (vorschlag, i) {
            var option = document.createElement('li');
            option.id = z.liste.id + '-' + i;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.setAttribute('data-index', String(i));
            option.textContent = vorschlag.text;
            z.liste.appendChild(option);
        });
        z.status.textContent = ansage(vorschlaege.length);
        if (vorschlaege.length === 0) {
            schliessen(feld, z);

            return;
        }
        z.aktiv = -1;
        z.liste.hidden = false;
        feld.setAttribute('aria-expanded', 'true');
        feld.removeAttribute('aria-activedescendant');
    }

    function feldDesFormulars(formular, name) {
        var feld = formular ? formular.elements.namedItem(name) : null;

        return feld instanceof HTMLInputElement || feld instanceof HTMLSelectElement ? feld : null;
    }

    function setzen(feld, wert) {
        feld.value = wert;
        feld.dispatchEvent(new Event('input', { bubbles: true }));
        feld.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function uebernehmen(feld, z, index) {
        var vorschlag = z.vorschlaege[index];
        if (!vorschlag) {
            return;
        }
        var werte = felderFuer(vorschlag);
        var formular = feld.form;
        z.still = true;
        try {
            FELDER.forEach(function (name) {
                var ziel = name === 'strasse' ? feld : feldDesFormulars(formular, name);
                if (ziel !== null && werte[name] !== undefined) {
                    setzen(ziel, werte[name]);
                }
            });
            if (werte.land !== undefined && formular) {
                var land = formular.querySelector('[data-land-iso]') || feldDesFormulars(formular, 'land');
                if (land instanceof HTMLSelectElement) {
                    var vorhanden = Array.prototype.some.call(land.options, function (o) { return o.value === werte.land; });
                    if (vorhanden) {
                        setzen(land, werte.land);
                    }
                } else if (land instanceof HTMLInputElement) {
                    setzen(land, werte.land);
                }
            }
            if (formular) {
                formular.dispatchEvent(new CustomEvent('anschrift-vorschlag', { bubbles: true, detail: werte }));
            }
        } finally {
            z.still = false;
        }
        schliessen(feld, z);
        z.status.textContent = 'Anschrift übernommen: ' + vorschlag.text + '.';
        feld.focus();
    }

    function fragen(feld, z) {
        var formular = feld.form;
        var adresse = feld.getAttribute('data-anschrift-vorschlaege');
        var csrf = formular ? feldDesFormulars(formular, '_csrf') : null;
        if (!adresse || csrf === null) {
            return;
        }
        if (z.abbruch !== null) {
            z.abbruch.abort();
        }
        z.abbruch = typeof AbortController === 'function' ? new AbortController() : null;
        var nummer = ++z.nummer;

        var daten = new FormData();
        daten.append('_csrf', csrf.value);
        daten.append('strasse', feld.value);
        ['plz', 'ort'].forEach(function (name) {
            var anderes = feldDesFormulars(formular, name);
            daten.append(name, anderes !== null ? anderes.value : '');
        });

        window.fetch(adresse, {
            method: 'POST',
            body: daten,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf.value, Accept: 'application/json' },
            signal: z.abbruch !== null ? z.abbruch.signal : undefined,
        }).then(function (antwort) {
            return antwort.ok ? antwort.json() : null;
        }).then(function (json) {
            // Only the answer to the latest question, and only while the field still has the focus.
            if (nummer === z.nummer && document.activeElement === feld) {
                zeigen(feld, z, vorschlaegeAus(json));
            }
        }).catch(function () {
            // No suggestions - the form works as before.
        });
    }

    document.addEventListener('input', function (ereignis) {
        var feld = ereignis.target;
        if (!(feld instanceof HTMLInputElement) || !feld.matches(SELEKTOR)) {
            return;
        }
        var z = zustand(feld);
        if (z.still) {
            return;
        }
        clearTimeout(z.uhr);
        if (!sollSuchen(feld.value)) {
            ++z.nummer;
            schliessen(feld, z);

            return;
        }
        z.uhr = setTimeout(function () { fragen(feld, z); }, DELAY_MS);
    });

    document.addEventListener('keydown', function (ereignis) {
        var feld = ereignis.target;
        if (!(feld instanceof HTMLInputElement) || !feld.matches(SELEKTOR)) {
            return;
        }
        var z = zustand(feld);
        var offen = !z.liste.hidden;
        switch (ereignis.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                if (z.vorschlaege.length === 0) {
                    return;
                }
                ereignis.preventDefault();
                if (!offen) {
                    z.liste.hidden = false;
                    feld.setAttribute('aria-expanded', 'true');
                }
                markieren(feld, z, naechsterIndex(z.aktiv, z.vorschlaege.length, ereignis.key));
                break;
            case 'Enter':
                if (offen && z.aktiv >= 0) {
                    // Take the suggestion instead of sending the form.
                    ereignis.preventDefault();
                    uebernehmen(feld, z, z.aktiv);
                }
                break;
            case 'Escape':
                if (offen) {
                    ereignis.preventDefault();
                    schliessen(feld, z);
                }
                break;
            case 'Tab':
                schliessen(feld, z);
                break;
        }
    });

    // A press on an option keeps the focus in the field; the click takes it.
    document.addEventListener('mousedown', function (ereignis) {
        if (ereignis.target instanceof Element && ereignis.target.closest('.anschrift-vorschlaege')) {
            ereignis.preventDefault();
        }
    });

    document.addEventListener('click', function (ereignis) {
        var option = ereignis.target instanceof Element ? ereignis.target.closest('.anschrift-vorschlaege [role="option"]') : null;
        var feld = option ? option.parentElement.previousElementSibling : null;
        if (!(feld instanceof HTMLInputElement) || !feld.matches(SELEKTOR)) {
            return;
        }
        uebernehmen(feld, zustand(feld), Number(option.getAttribute('data-index')));
    });

    document.addEventListener('focusout', function (ereignis) {
        var feld = ereignis.target;
        if (feld instanceof HTMLInputElement && feld.matches(SELEKTOR) && zustaende.has(feld)) {
            schliessen(feld, zustand(feld));
        }
    });

    document.addEventListener('htmx:load', function (ereignis) {
        einrichten(ereignis.target);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { einrichten(document); });
    } else {
        einrichten(document);
    }
})();
