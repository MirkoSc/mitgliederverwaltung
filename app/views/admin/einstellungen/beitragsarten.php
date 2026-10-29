<?php

/**
 * Settings section Beitragsarten (docs/spec/08-beitraege.md section 1).
 *
 * @var list<\App\Domain\Beitragsart> $beitragsarten
 * @var list<\App\Domain\BeitragsRegel> $regeln
 * @var list<\App\Domain\AktivArt> $aktivArten
 * @var string $csrf
 */

$zeilen = $beitragsarten;
$zeilen[] = null;
$zeilen[] = null;
?>
<p class="feld-hinweis">Ein geänderter Betrag gilt nur für Jahresbeiträge, die danach erzeugt werden. Die Regel steuert nur Hinweise, sie sperrt nichts. „Löschen“ entfernt eine Beitragsart ohne Mitglieder; sonst wird sie nur deaktiviert.</p>
<form method="post" action="/admin/beitragsarten" class="formular formular-liste">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php foreach ($zeilen as $i => $b): ?>
        <fieldset class="feldgruppe">
            <legend><?= $b === null ? 'Neue Beitragsart' : e($b->name) ?></legend>
            <input type="hidden" name="zeile[<?= e($i) ?>][id]" value="<?= e($b?->id ?? '') ?>">
            <div class="feld">
                <label for="beitragsart-<?= e($i) ?>-name">Name</label>
                <input id="beitragsart-<?= e($i) ?>-name" name="zeile[<?= e($i) ?>][name]" type="text" value="<?= e($b?->name ?? '') ?>" maxlength="100">
            </div>
            <div class="feld">
                <label for="beitragsart-<?= e($i) ?>-betrag">Jahresbetrag in Euro</label>
                <input id="beitragsart-<?= e($i) ?>-betrag" name="zeile[<?= e($i) ?>][betrag]" type="text" inputmode="decimal" value="<?= e($b === null ? '' : \App\Support\Geld::eingabeWert($b->betragCent)) ?>">
            </div>
            <div class="feld">
                <label for="beitragsart-<?= e($i) ?>-regel">Regel</label>
                <select id="beitragsart-<?= e($i) ?>-regel" name="zeile[<?= e($i) ?>][regel]">
                    <option value="">Keine</option>
                    <?php foreach ($regeln as $regel): ?>
                        <option value="<?= e($regel->value) ?>"<?= $b?->regel === $regel ? ' selected' : '' ?>><?= e($regel->bezeichnung()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="feld">
                <label for="beitragsart-<?= e($i) ?>-aktivart">Passt zu</label>
                <select id="beitragsart-<?= e($i) ?>-aktivart" name="zeile[<?= e($i) ?>][aktiv_art]">
                    <?php foreach ($aktivArten as $art): ?>
                        <option value="<?= e($art->value) ?>"<?= ($b?->aktivArt ?? \App\Domain\AktivArt::Egal) === $art ? ' selected' : '' ?>><?= e($art->bezeichnung()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="feld">
                <label for="beitragsart-<?= e($i) ?>-position">Position</label>
                <input id="beitragsart-<?= e($i) ?>-position" name="zeile[<?= e($i) ?>][position]" type="number" min="1" value="<?= e($i + 1) ?>">
            </div>
            <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][aktiv]" value="1"<?= $b === null || $b->aktiv ? ' checked' : '' ?>> Aktiv</label>
            <?php if ($b !== null): ?>
                <label class="feld-haken"><input type="checkbox" name="zeile[<?= e($i) ?>][loeschen]" value="1"> Löschen</label>
            <?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
    <button type="submit" class="knopf knopf-haupt">Beitragsarten speichern</button>
</form>
