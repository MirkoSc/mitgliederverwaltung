/*
 * "Drucken" on the backup code page (app/views/konto/backup-codes.php): a
 * button cannot call window.print() without inline script, which the CSP
 * forbids - so any [data-drucken] button prints the page.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (ereignis) {
        var ziel = ereignis.target instanceof Element ? ereignis.target.closest('[data-drucken]') : null;
        if (ziel !== null) {
            window.print();
        }
    });
})();
