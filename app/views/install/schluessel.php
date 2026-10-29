<?php

/**
 * Step 4, second half: the server key as "Sicherungsschlüssel" - shown once,
 * in the answer to the request that created the admin (or a new key), never
 * on reloading the page. Without it no backup and no stored file can be
 * read again (docs/spec/02-sicherheit.md section 2).
 *
 * Grouped in fours for copying by hand; the restore ignores the spaces
 * (App\Service\Backup\RestoreService::schluesselAusEingabe()).
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 * @var string|null $schluessel base64 server key, only in the one answer that shows it
 */
$ueberschrift = 'Sicherungsschlüssel';
$gruppen = isset($schluessel) ? str_split($schluessel, 4) : null;
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>

    <?php if ($gruppen !== null): ?>
        <p>Der Admin-Zugang ist angelegt. Dieser Schlüssel verschlüsselt Backups und gespeicherte Dateien.
            Ohne ihn lässt sich ein Backup nach einem Serverumzug oder Datenverlust nicht mehr einspielen.</p>
        <figure class="sicherungskarte">
            <figcaption>Sicherungsschlüssel der Mitgliederverwaltung<span class="sicherungskarte-datum">, erstellt am <?= e(date('d.m.Y')) ?></span></figcaption>
            <p class="sicherungskarte-schluessel" aria-label="Sicherungsschlüssel: <?= e(implode(' ', $gruppen)) ?>">
                <?php foreach ($gruppen as $gruppe): ?><span><?= e($gruppe) ?></span> <?php endforeach; ?>
            </p>
            <p class="sicherungskarte-hinweis">Getrennt von den Backups aufbewahren, zum Beispiel ausgedruckt bei einem zweiten Vorstandsmitglied.
                Groß- und Kleinschreibung zählen; die Leerzeichen nicht.</p>
        </figure>
        <p class="hinweis hinweis-warnung">Der Schlüssel wird nur dieses eine Mal angezeigt. Drucken Sie ihn jetzt aus oder schreiben Sie ihn ab.</p>
        <p class="knopfreihe install-drucken">
            <button type="button" class="knopf" data-drucken hidden>Schlüssel drucken</button>
        </p>
    <?php else: ?>
        <p class="hinweis hinweis-info">Der Sicherungsschlüssel wurde bereits angezeigt und wird aus Sicherheitsgründen nicht noch einmal gezeigt.
            Haben Sie ihn nicht notiert, erzeugen Sie einen neuen – der bisherige wurde noch für nichts verwendet.</p>
    <?php endif; ?>

    <form method="post" action="/install/schluessel" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="feld-haken">
            <input type="checkbox" name="notiert" value="1" required>
            Ich habe den Sicherungsschlüssel ausgedruckt oder notiert.
        </label>
        <div class="formular-aktionen">
            <button type="submit" name="aktion" value="bestaetigt" class="knopf knopf-haupt">Weiter zum Mailversand</button>
        </div>
    </form>
    <?php if ($gruppen === null): ?>
        <form method="post" action="/install/schluessel">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" name="aktion" value="neu" class="knopf">Neuen Schlüssel erzeugen</button>
        </form>
    <?php endif; ?>
</section>
