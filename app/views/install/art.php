<?php

/**
 * Step 3: a new, empty installation or one restored from a backup.
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 */
$ueberschrift = 'Art der Installation';
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>
    <p class="hinweis hinweis-ok">Die Verbindung zur Datenbank steht.</p>
    <form method="post" action="/install/art" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset class="feldgruppe install-wahl">
            <legend>Wie soll es weitergehen?</legend>
            <label class="feld-haken">
                <input type="radio" name="art" value="frisch" checked>
                <span><strong>Neu installieren</strong><br>
                    <span class="gedaempft">Leere Mitgliederverwaltung mit einem ersten Admin-Zugang.</span></span>
            </label>
            <label class="feld-haken">
                <input type="radio" name="art" value="backup">
                <span><strong>Backup einspielen</strong><br>
                    <span class="gedaempft">Umzug oder Wiederherstellung: alle Daten einer bisherigen Installation. Sie brauchen dafür deren Sicherungsschlüssel.</span></span>
            </label>
        </fieldset>
        <div class="formular-aktionen">
            <button type="submit" class="knopf knopf-haupt">Weiter</button>
        </div>
    </form>
    <?php require __DIR__ . '/../partials/install-neu.php'; ?>
</section>
