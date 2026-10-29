<?php

/**
 * Restore, first page: which backup and its key (04 section 6).
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 * @var list<string> $backups backups put into shared/var/backups/ by FTP
 * @var string $uploadGrenze what PHP takes in one upload, '' if unknown
 */
$ueberschrift = 'Backup auswählen';
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>
    <p>Eingespielt wird ein verschlüsseltes Backup (<code>backup_….zip.enc</code>) einer bisherigen Installation.
        Was in dieser Datenbank schon steht, wird dabei ersetzt.</p>
    <form method="post" action="/install/wiederherstellen" class="formular" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset class="feldgruppe">
            <legend>Backup</legend>
            <div class="feld">
                <label for="restore-backup">Backup hochladen</label>
                <input id="restore-backup" name="backup" type="file" accept=".enc" aria-describedby="restore-backup-hinweis">
                <p class="feld-hinweis" id="restore-backup-hinweis">
                    <?= $uploadGrenze !== '' ? 'Bis ' . e($uploadGrenze) . '. ' : '' ?>Größere Backups legen Sie per FTP nach <code>shared/var/backups/</code> und laden diese Seite neu.
                </p>
            </div>
            <?php if ($backups !== []): ?>
                <div class="feld">
                    <label for="restore-datei">oder aus <code>shared/var/backups/</code></label>
                    <select id="restore-datei" name="backup_datei">
                        <option value="">– keines –</option>
                        <?php foreach ($backups as $name): ?>
                            <option value="<?= e($name) ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </fieldset>
        <fieldset class="feldgruppe">
            <legend>Sicherungsschlüssel</legend>
            <div class="feld">
                <label for="restore-schluessel">Schlüssel abschreiben</label>
                <input id="restore-schluessel" name="schluessel" type="text" autocomplete="off" spellcheck="false" aria-describedby="restore-schluessel-hinweis">
                <p class="feld-hinweis" id="restore-schluessel-hinweis">44 Zeichen vom Ausdruck; Leerzeichen sind egal.</p>
            </div>
            <div class="feld">
                <label for="restore-config">oder die config.php der bisherigen Installation hochladen</label>
                <input id="restore-config" name="config" type="file" accept=".php">
            </div>
        </fieldset>
        <div class="formular-aktionen">
            <button type="submit" class="knopf knopf-haupt">Backup einspielen</button>
        </div>
    </form>
    <?php require __DIR__ . '/../partials/install-neu.php'; ?>
</section>
