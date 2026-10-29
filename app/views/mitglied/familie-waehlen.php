<?php

/**
 * "Familie wählen" (docs/spec/07-mitglieder.md section 3): search the
 * families by name, join one with a role, or create a new one.
 *
 * @var \App\Domain\Mitglied $mitglied
 * @var string $suche
 * @var list<\App\Domain\Familie> $treffer
 * @var string $vorschlag
 * @var list<\App\Domain\FamilienRolle> $rollen
 * @var string|null $csrf
 */

use App\App\FamilieController;
use App\App\MitgliedController;

$id = (int) $mitglied->id;
$rollenwahl = static function (string $feldId) use ($rollen): string {
    $html = '<div class="feld"><label for="' . e($feldId) . '">Rolle</label><select id="' . e($feldId) . '" name="rolle">';
    foreach ($rollen as $rolle) {
        $html .= '<option value="' . e($rolle->value) . '">' . e($rolle->bezeichnung()) . '</option>';
    }

    return $html . '</select></div>';
};
?>
<div class="seitenkopf">
    <h1>Familie wählen</h1>
    <p><?= e($mitglied->anzeigeName()) ?>, <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
</div>

<form method="get" action="<?= e(FamilieController::adresse($id)) ?>" class="suche" role="search">
    <div class="feld">
        <label for="familie-suche">Familie suchen</label>
        <input type="search" id="familie-suche" name="q" value="<?= e($suche) ?>" autocomplete="off">
    </div>
    <button type="submit" class="knopf">Suchen</button>
</form>

<h2>Beitreten</h2>
<?php if ($treffer === []): ?>
    <p>Keine Familie gefunden<?= $suche !== '' ? ' zu „' . e($suche) . '"' : '' ?>. Unten können Sie eine neue anlegen.</p>
<?php else: ?>
    <?php foreach ($treffer as $i => $familie): ?>
        <form method="post" action="<?= e(FamilieController::adresse($id, 'beitreten')) ?>" class="formular knopfreihe">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <input type="hidden" name="familie_id" value="<?= e($familie->id) ?>">
            <p><strong><?= e($familie->name) ?></strong></p>
            <?= $rollenwahl('rolle-beitreten-' . $i) ?>
            <button type="submit" class="knopf">Dieser Familie beitreten</button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Neue Familie</h2>
<form method="post" action="<?= e(FamilieController::adresse($id, 'neu')) ?>" class="formular knopfreihe">
    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
    <div class="feld">
        <label for="familie-neu-name">Name der neuen Familie</label>
        <input type="text" id="familie-neu-name" name="name" value="<?= e($vorschlag) ?>" maxlength="200">
    </div>
    <?= $rollenwahl('rolle-neu') ?>
    <button type="submit" class="knopf knopf-haupt">Familie anlegen</button>
</form>

<p><a class="knopf knopf-still" href="<?= e(MitgliedController::adresse($id)) ?>">Zurück zum Mitglied</a></p>
