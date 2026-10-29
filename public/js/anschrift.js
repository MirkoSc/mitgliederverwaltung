/*
 * "Anschrift des Zahlers übernehmen" in the hints of an opened member
 * (app/views/mitglied/hinweise.php): copies the payer's address from the
 * button's data-* attributes into the form of the tab "Daten". Nothing is
 * saved - the form is, when its button is pressed. Delegated on the
 * document, because htmx swaps the member in and out.
 */
(function () {
    'use strict';

    var FELDER = ['strasse', 'plz', 'ort', 'land'];

    document.addEventListener('click', function (ereignis) {
        var knopf = ereignis.target instanceof Element ? ereignis.target.closest('[data-anschrift-uebernehmen]') : null;
        var formular = document.getElementById('mitglied-formular');
        if (knopf === null || formular === null) {
            return;
        }

        FELDER.forEach(function (name) {
            var feld = formular.elements.namedItem(name);
            if (feld instanceof HTMLInputElement) {
                feld.value = knopf.getAttribute('data-' + name) || '';
                feld.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        var stand = knopf.parentElement !== null ? knopf.parentElement.querySelector('.anschrift-stand') : null;
        if (stand !== null) {
            stand.textContent = 'Übernommen. Gespeichert wird erst mit „Mitglied speichern“ unten im Formular.';
        }
        var strasse = formular.elements.namedItem('strasse');
        if (strasse instanceof HTMLInputElement) {
            strasse.scrollIntoView({ block: 'center' });
        }
    });
})();
