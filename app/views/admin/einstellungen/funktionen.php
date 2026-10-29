<?php

/**
 * Settings section Funktionen (docs/spec/07-mitglieder.md section 4).
 *
 * @var list<\App\Domain\Funktion> $funktionen
 * @var string $csrf
 */

$zeilen = $funktionen;
$zeilen[] = null;
$zeilen[] = null;
?>
<p class="feld-hinweis">Mit „Lizenz“ fragt das Mitgliedsformular nach der Lizenznummer (zum Beispiel Übungsleiter). „Löschen“ entfernt eine Funktion, die niemand hat; sonst wird sie nur deaktiviert.</p>
<form method="post" action="/admin/funktionen" class="formular formular-liste">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php foreach ($zeilen as $i => $f): ?>
        <fieldset class="feldgruppe">
            <legend><?= $f === null ? 'Neue Funktion' : e($f->name) ?></legend>
            <input type="hidden" name="zeile[<?= e($i) ?>][id]" value="<?= e($f?->id ?? '') ?>">
            <div class="feld">
                <label for="funktion-<?= e($i) ?>-name">Name</label>
                <input id="funktion-<?= e($i) ?>-name" name="zeile[<?= e($i) ?>][name]" type="text" value="<?= e($f?->name ?? '') ?>" maxlength="100">
            </div>
            <div class="feld">
                <label for="funktion-<?= e($i) ?>-position">Position</label>
                <input id="funktion-<?= e($i) ?>-position" name="zeile[<?= e($i) ?>][position]" type="number" min="1" value="<?= e($i + 1) ?>">
            </div>
            <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][mit_lizenz]" value="1"<?= $f?->mitLizenz ? ' checked' : '' ?>> Mit Lizenz</label>
            <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][aktiv]" value="1"<?= $f === null || $f->aktiv ? ' checked' : '' ?>> Aktiv</label>
            <?php if ($f !== null): ?>
                <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][loeschen]" value="1"> Löschen</label>
            <?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
    <button type="submit" class="knopf knopf-haupt">Funktionen speichern</button>
</form>
