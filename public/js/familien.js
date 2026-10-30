/*
 * The families page (issue #92): "Anschrift übernehmen (n)" counts the
 * ticked persons while they are ticked, and the button "Andere Anschrift
 * zeigen" - for a browser without script - is hidden, because htmx shows
 * the other source as soon as it is chosen. Delegated on the document:
 * htmx swaps the family in and out.
 */
(function () {
    'use strict';

    function zaehlen(formular) {
        var anzahl = formular.querySelector('[data-anschrift-anzahl]');
        if (anzahl !== null) {
            anzahl.textContent = String(formular.querySelectorAll('input[name="mitglieder[]"]:checked').length);
        }
    }

    function ohneSkriptVerbergen(wurzel) {
        wurzel.querySelectorAll('.nur-ohne-skript').forEach(function (element) {
            element.hidden = true;
        });
    }

    document.addEventListener('change', function (ereignis) {
        var feld = ereignis.target;
        if (feld instanceof HTMLInputElement && feld.name === 'mitglieder[]') {
            var formular = feld.closest('[data-anschrift-formular]');
            if (formular !== null) {
                zaehlen(formular);
            }
        }
    });

    document.addEventListener('htmx:afterSettle', function () {
        ohneSkriptVerbergen(document);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            ohneSkriptVerbergen(document);
        });
    } else {
        ohneSkriptVerbergen(document);
    }
})();
