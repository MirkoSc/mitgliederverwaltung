<?php

/**
 * The tab "Familie" of an opened member (App\App\Mitglied\
 * FamilieReiter, docs/spec/07-mitglieder.md section 3).
 *
 * @var \App\Domain\Mitglied|null $mitglied
 * @var \App\Domain\Familie|null $familie
 * @var list<\App\Domain\Mitglied> $andere the other persons of the family
 * @var list<\App\Domain\FamilienRolle> $rollen
 * @var bool $darfPflegen
 * @var string|null $csrf
 * @var \App\App\Mitglied\Einbettung|null $einbettung the page the member is shown on (MitgliedAnsicht)
 */

use App\App\FamilieController;
use App\App\FamilienController;
use App\App\Mitglied\Einbettung;

if ($mitglied === null) {
    return;
}
$id = (int) $mitglied->id;
$einbettung ??= Einbettung::mitgliederSeite();
$rolleAktuell = $mitglied->familieRolle;
?>
<section class="familie" aria-labelledby="familie-titel">
    <h2 id="familie-titel" class="nur-lesehilfe">Familie</h2>
    <?php if ($familie === null): ?>
        <p>Keine Familie.</p>
        <?php if ($darfPflegen): ?>
            <p><a class="knopf" href="<?= e($einbettung->mitRueckweg(FamilieController::adresse($id))) ?>">Familie wählen oder anlegen</a></p>
        <?php endif; ?>
    <?php else: ?>
        <p><strong><?= e($familie->name) ?></strong><?= $rolleAktuell !== null ? ', Rolle: ' . e($rolleAktuell->bezeichnung()) : '' ?></p>
        <?php if ($einbettung->pfad !== FamilienController::PFAD): ?>
            <?php /* The whole family - all persons, their addresses, the changes - on the families page (#92). */ ?>
            <p><a href="<?= e(FamilienController::adresse($familie->id)) ?>">In Familien öffnen</a></p>
        <?php endif; ?>
        <?php if ($andere === []): ?>
            <p>Sonst ist niemand in dieser Familie.</p>
        <?php else: ?>
            <ul class="familie-personen">
                <?php foreach ($andere as $person): ?>
                    <li>
                        <a <?= $einbettung->link($einbettung->adresse((int) $person->id), 'mitglied') ?>><?= e($person->anzeigeName()) ?></a>
                        <span class="nummer">Nr. <?= e($person->mitgliedsnummer) ?></span>,
                        <?= e($person->familieRolle?->bezeichnung() ?? 'ohne Rolle') ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($darfPflegen): ?>
            <form method="post" action="<?= e(FamilieController::adresse($id, 'rolle')) ?>" class="formular knopfreihe">
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <?= $einbettung->feld() ?>
                <div class="feld">
                    <label for="familie-rolle">Rolle in der Familie</label>
                    <select id="familie-rolle" name="rolle">
                        <?php foreach ($rollen as $rolle): ?>
                            <option value="<?= e($rolle->value) ?>"<?= $rolle === $rolleAktuell ? ' selected' : '' ?>><?= e($rolle->bezeichnung()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="knopf">Rolle speichern</button>
            </form>
            <form method="post" action="<?= e(FamilieController::adresse($id, 'umbenennen')) ?>" class="formular knopfreihe">
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <?= $einbettung->feld() ?>
                <div class="feld">
                    <label for="familie-name">Name der Familie</label>
                    <input type="text" id="familie-name" name="name" value="<?= e($familie->name) ?>" maxlength="200">
                </div>
                <button type="submit" class="knopf">Umbenennen</button>
            </form>
            <form method="post" action="<?= e(FamilieController::adresse($id, 'verlassen')) ?>" class="knopfreihe">
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <?= $einbettung->feld() ?>
                <button type="submit" class="knopf knopf-still">Aus der Familie nehmen</button>
                <?php if ($andere === []): ?>
                    <span class="feld-hinweis">Die Familie hat dann niemanden mehr und wird gelöscht.</span>
                <?php endif; ?>
            </form>
            <p><a href="<?= e($einbettung->mitRueckweg(FamilieController::adresse($id))) ?>">Andere Familie wählen</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
