<?php

/**
 * Warning when shared/ or releases/ carry no locking .htaccess
 * (App\Service\Update\DenyHtaccess) - harmless as long as the domain
 * points at web/, but config.php and the backups would be reachable if it
 * pointed one level higher.
 *
 * @var string $ungeschuetzt
 */
?>
<?php if (($ungeschuetzt ?? '') !== ''): ?>
    <p class="hinweis hinweis-warnung">Ohne .htaccess-Sperre: <?= e($ungeschuetzt) ?>. Solange die Domain auf den
        Ordner <code>web</code> zeigt, ist das unkritisch – zeigt sie eine Ebene höher, wären Konfiguration und Backups abrufbar.
        Bitte per FTP prüfen.</p>
<?php endif; ?>
