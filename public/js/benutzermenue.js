/*
 * Benutzermenü (app/views/partials/kopf.php): ein <details>, das ohne
 * Skript funktioniert. Dieses Skript ergänzt nur, was <details> nicht kann:
 * Escape schließt das Menü und gibt den Fokus an den Auslöser zurück, ein
 * Klick daneben schließt es (docs/spec/06-oberflaeche.md §4: vollständig mit
 * Tastatur bedienbar).
 *
 * Die Entscheidung steckt in sollSchliessen() - reine Logik, getestet mit
 * node --test tests/js.
 */
(function () {
    'use strict';

    /**
     * @param {{art: string, taste?: string, innerhalb: boolean, offen: boolean}} ereignis
     * @returns {boolean}
     */
    function sollSchliessen(ereignis) {
        if (!ereignis.offen) {
            return false;
        }
        if (ereignis.art === 'keydown') {
            return ereignis.taste === 'Escape';
        }
        if (ereignis.art === 'click') {
            return !ereignis.innerhalb;
        }

        return false;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { sollSchliessen: sollSchliessen };
    }

    if (typeof document === 'undefined') {
        return;
    }

    function behandeln(art, ereignis) {
        document.querySelectorAll('details.benutzermenue').forEach(function (menue) {
            var schliessen = sollSchliessen({
                art: art,
                taste: ereignis.key,
                innerhalb: menue.contains(ereignis.target),
                offen: menue.open,
            });
            if (!schliessen) {
                return;
            }
            menue.open = false;
            if (art === 'keydown') {
                var ausloeser = menue.querySelector('summary');
                if (ausloeser) {
                    ausloeser.focus();
                }
            }
        });
    }

    document.addEventListener('keydown', function (ereignis) {
        behandeln('keydown', ereignis);
    });
    document.addEventListener('click', function (ereignis) {
        behandeln('click', ereignis);
    });
}());
