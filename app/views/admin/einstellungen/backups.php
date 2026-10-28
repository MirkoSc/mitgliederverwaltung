<?php

/**
 * Settings section Backups (docs/spec/04-installation-und-update.md
 * section 6): make a backup, cancel an unfinished one, download one of the
 * last ten. Only components of /admin/designsystem; the step chain runs in
 * public/js/backups.js - an external script, never an inline one (CLAUDE.md
 * section 4). The settings page renders sections without a script list, so
 * the section names its script itself.
 *
 * @var list<array{name: string, groesse: int, erstellt_am: \DateTimeImmutable}> $backups
 * @var bool $laeuft a chain is running and was advanced recently
 * @var bool $unfertig a chain is unfinished (running or abandoned)
 * @var int $behalten
 * @var string $version
 * @var string $csrf
 */

$groesse = static function (int $bytes): string {
    $einheiten = ['Byte', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $wert = (float) $bytes;
    while ($wert >= 1024.0 && $i < count($einheiten) - 1) {
        $wert /= 1024.0;
        $i++;
    }

    return number_format($wert, $i === 0 ? 0 : 1, ',', '.') . ' ' . $einheiten[$i];
};
?>
<p>
    Ein Backup sichert die Datenbank mit allen Mitgliedern, die Nachweise und Lastschriftdateien
    und das Vereinslogo. Es ist mit dem Sicherungsschlüssel verschlüsselt: Ohne ihn lässt es sich
    weder lesen noch einspielen. Bewahren Sie den Ausdruck des Schlüssels deshalb getrennt vom
    Backup auf.
</p>
<p class="gedaempft">
    Eingespielt wird ein Backup bei einer neuen Installation. Aufbewahrt werden die letzten
    <?= e($behalten) ?> Backups; ältere werden beim nächsten Backup gelöscht.
</p>

<form method="post" action="/admin/backups" id="backup-erstellen"
      data-csrf="<?= e($csrf) ?>" data-laeuft="<?= $laeuft ? '1' : '0' ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <noscript>
        <p class="hinweis hinweis-fehler">Zum Erstellen eines Backups wird JavaScript benötigt.</p>
    </noscript>
    <p class="knopfreihe">
        <button type="submit" class="knopf knopf-haupt" id="backup-start"<?= $laeuft ? ' hidden' : '' ?>>Backup erstellen</button>
        <button type="button" class="knopf knopf-haupt" id="backup-fortsetzen"<?= $laeuft ? '' : ' hidden' ?>>Backup fortsetzen</button>
    </p>
</form>

<div id="backup-verlauf" class="backup-verlauf" hidden>
    <progress id="backup-fortschritt" max="100" aria-labelledby="backup-status"></progress>
    <p id="backup-status" aria-live="polite"></p>
    <p id="backup-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
</div>

<?php if ($unfertig): ?>
    <form method="post" action="/admin/backups/abbrechen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="knopfreihe">
            <?php if (!$laeuft): ?>
                <span class="gedaempft">Ein abgebrochenes Backup belegt noch Speicherplatz.</span>
            <?php endif; ?>
            <button type="submit" class="knopf knopf-still">Backup abbrechen</button>
        </p>
    </form>
<?php endif; ?>

<h3>Vorhandene Backups</h3>
<?php if ($backups === []): ?>
    <p class="leer">Noch kein Backup vorhanden. Das erste entsteht mit „Backup erstellen“.</p>
<?php else: ?>
    <div class="tabelle-rahmen">
        <table class="liste">
            <caption>Neuestes zuerst</caption>
            <thead>
                <tr>
                    <th scope="col">Erstellt</th>
                    <th scope="col" class="zahl">Größe</th>
                    <th scope="col"><span class="nur-lesehilfe">Herunterladen</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td class="liste-titel"><?= e($backup['erstellt_am']->format('d.m.Y, H:i')) ?> Uhr</td>
                        <td data-label="Größe" class="zahl"><?= e($groesse($backup['groesse'])) ?></td>
                        <td>
                            <a class="knopf" href="/admin/backups/<?= e($backup['name']) ?>" download>
                                Herunterladen<span class="nur-lesehilfe"> (Backup vom <?= e($backup['erstellt_am']->format('d.m.Y, H:i')) ?> Uhr)</span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script src="/js/backups.js?v=<?= e($version) ?>" defer></script>
