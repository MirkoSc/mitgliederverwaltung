<?php

/**
 * Restore, second page: public/js/install.js runs the step chain - one
 * short request per step - and shows the result. The last step writes
 * config.php.
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 */
$ueberschrift = 'Backup einspielen';
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>
    <div id="restore" class="install-verlauf" data-csrf="<?= e($csrf) ?>">
        <noscript><p class="hinweis hinweis-fehler">Das Einspielen braucht JavaScript. Bitte JavaScript einschalten und die Seite neu laden.</p></noscript>
        <progress id="restore-fortschritt" max="100" value="0">0 %</progress>
        <p id="restore-status" role="status">Backup wird eingespielt …</p>
        <p id="restore-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
        <p class="knopfreihe">
            <button type="button" id="restore-wiederholen" class="knopf" hidden>Schritt wiederholen</button>
        </p>
    </div>
    <div id="restore-fertig" hidden>
        <p class="hinweis hinweis-ok">Das Backup ist eingespielt und die Installation abgeschlossen. Sie melden sich mit den bisherigen Zugängen an.</p>
        <?php $cronUrl = null; require __DIR__ . '/../partials/install-cron.php'; ?>
        <p id="restore-abgelehnt" class="hinweis hinweis-warnung" hidden></p>
        <p id="restore-setup" class="hinweis hinweis-warnung" hidden>Die Datei <code>setup.php</code> ließ sich nicht löschen. Bitte löschen Sie sie per FTP aus dem DocumentRoot.</p>
        <p class="knopfreihe"><a class="knopf knopf-haupt" href="/anmelden">Zur Anmeldung</a></p>
    </div>
    <?php require __DIR__ . '/../partials/install-neu.php'; ?>
</section>
