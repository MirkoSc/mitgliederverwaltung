<?php

/**
 * The short question before "Austritt zurücknehmen"
 * (docs/spec/07-mitglieder.md section 1; 06 section 4 "Rückfragen"):
 * leaving date and reason are cleared, as a new version.
 *
 * @var \App\Domain\Mitglied $mitglied
 * @var bool $ehemalig
 * @var string|null $meldung somebody else saved in between
 * @var string|null $csrf
 * @var \App\App\Mitglied\Einbettung $einbettung where the member was opened
 */

use App\App\MitgliedController;

$id = (int) $mitglied->id;
?>
<div class="seitenkopf">
    <h1>Austritt zurücknehmen?</h1>
    <p><?= e($mitglied->anzeigeName()) ?>, <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
</div>

<?php if (($meldung ?? null) !== null): ?>
    <p class="hinweis hinweis-warnung" role="alert"><?= e($meldung) ?></p>
<?php endif; ?>

<p><?= $ehemalig ? 'Ausgetreten am' : 'Austritt vermerkt zum' ?> <?= e(datum($mitglied->austrittsdatum)) ?><?= $mitglied->austrittsgrund !== '' ? ' (' . e($mitglied->austrittsgrund) . ')' : '' ?>.
    Austrittsdatum und Grund werden entfernt; die Person ist dann wieder Mitglied wie vorher. Der bisherige Stand
    bleibt im Verlauf.</p>

<form method="post" action="<?= e(MitgliedController::zuruecknehmenAdresse($id)) ?>" class="knopfreihe">
    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
    <input type="hidden" name="version" value="<?= e($mitglied->version) ?>">
    <?= $einbettung->feld() ?>
    <button type="submit" class="knopf knopf-haupt">Austritt zurücknehmen</button>
    <a class="knopf knopf-still" href="<?= e($einbettung->adresse($id)) ?>">Abbrechen</a>
</form>
