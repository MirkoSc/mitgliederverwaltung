<?php

/**
 * Step 6 is done: config.php exists, /install is closed.
 *
 * @var string $cronUrl
 * @var bool $setupUebrig setup.php could not be deleted
 * @var bool $mailEingerichtet
 */
?>
<section class="install">
    <header class="install-kopf">
        <p class="install-marke">Mitgliederverwaltung einrichten</p>
        <h1>Installation abgeschlossen</h1>
    </header>
    <p class="hinweis hinweis-ok">Die Mitgliederverwaltung ist eingerichtet. Bei der ersten Anmeldung richten Sie den zweiten Faktor ein.</p>
    <?php if ($setupUebrig): ?>
        <p class="hinweis hinweis-warnung">Die Datei <code>setup.php</code> ließ sich nicht löschen. Bitte löschen Sie sie per FTP aus dem DocumentRoot.</p>
    <?php endif; ?>
    <?php if (!$mailEingerichtet): ?>
        <p class="hinweis hinweis-info">Der Mailversand ist noch nicht eingerichtet. Das holen Sie unter Einstellungen › Mail nach.</p>
    <?php endif; ?>
    <?php require __DIR__ . '/../partials/install-cron.php'; ?>
    <p class="knopfreihe"><a class="knopf knopf-haupt" href="/anmelden">Zur Anmeldung</a></p>
</section>
