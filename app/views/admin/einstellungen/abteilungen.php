<?php

/**
 * Settings section Abteilungen (docs/spec/07-mitglieder.md section 2).
 *
 * @var list<\App\Domain\Abteilung> $abteilungen
 * @var string $csrf
 */

$zeilen = $abteilungen;
// Two empty rows to add to; saving drops the ones left without a name.
$zeilen[] = null;
$zeilen[] = null;
?>
<p class="feld-hinweis">Die Reihenfolge richtet sich nach der Position. „Löschen“ entfernt eine Abteilung ohne Mitglieder; mit Mitgliedern wird sie nur deaktiviert.</p>
<form method="post" action="/admin/abteilungen" class="formular formular-liste">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php foreach ($zeilen as $i => $a): ?>
        <fieldset class="feldgruppe">
            <legend><?= $a === null ? 'Neue Abteilung' : e($a->name) ?></legend>
            <input type="hidden" name="zeile[<?= e($i) ?>][id]" value="<?= e($a?->id ?? '') ?>">
            <div class="feld">
                <label for="abteilung-<?= e($i) ?>-name">Name</label>
                <input id="abteilung-<?= e($i) ?>-name" name="zeile[<?= e($i) ?>][name]" type="text" value="<?= e($a?->name ?? '') ?>" maxlength="100">
            </div>
            <div class="feld">
                <label for="abteilung-<?= e($i) ?>-blsv">BLSV-Nummer</label>
                <input id="abteilung-<?= e($i) ?>-blsv" name="zeile[<?= e($i) ?>][blsv_nummer]" type="text" value="<?= e($a?->blsvNummer ?? '') ?>" maxlength="8" inputmode="numeric">
            </div>
            <div class="feld">
                <label for="abteilung-<?= e($i) ?>-position">Position</label>
                <input id="abteilung-<?= e($i) ?>-position" name="zeile[<?= e($i) ?>][position]" type="number" min="1" value="<?= e($i + 1) ?>">
            </div>
            <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][aktiv]" value="1"<?= $a === null || $a->aktiv ? ' checked' : '' ?>> Aktiv</label>
            <?php if ($a !== null): ?>
                <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][loeschen]" value="1"> Löschen</label>
            <?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
    <button type="submit" class="knopf knopf-haupt">Abteilungen speichern</button>
</form>
