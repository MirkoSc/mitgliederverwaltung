<?php

/**
 * The two fields of a new password - invitation, reset and own change use
 * the same pair, so the rule reads the same everywhere
 * (docs/spec/02-sicherheit.md section 3: at least 12 characters, no
 * composition rules).
 *
 * @var string $idPraefix
 * @var int $minLaenge
 * @var list<string> $fehler
 */
$hatFehler = ($fehler ?? []) !== [];
?>
<div class="feld">
    <label for="<?= e($idPraefix) ?>-passwort">Neues Passwort</label>
    <input id="<?= e($idPraefix) ?>-passwort" name="passwort" type="password" autocomplete="new-password" minlength="<?= e($minLaenge) ?>" required
           aria-describedby="<?= e($idPraefix) ?>-passwort-hinweis"<?= $hatFehler ? ' aria-invalid="true"' : '' ?>>
    <p class="feld-hinweis" id="<?= e($idPraefix) ?>-passwort-hinweis">Mindestens <?= e($minLaenge) ?> Zeichen. Ein Satz aus mehreren Wörtern ist leicht zu merken und schwer zu erraten.</p>
</div>
<div class="feld">
    <label for="<?= e($idPraefix) ?>-wiederholung">Neues Passwort wiederholen</label>
    <input id="<?= e($idPraefix) ?>-wiederholung" name="passwort_wiederholung" type="password" autocomplete="new-password" minlength="<?= e($minLaenge) ?>" required>
</div>
