<?php

/**
 * The opened family (issue #92): its persons with role and address, the
 * hints, and - for whoever may edit members - the ways to change it. Every
 * person opens on this page in place of the family (member building block
 * with "Zurück zur Familie"). Hints only: nothing here is locked because a
 * family is too small, has no payer or several addresses.
 *
 * @var \App\Domain\Familie $familie
 * @var list<\App\Domain\Mitglied> $personen
 * @var list<\App\Domain\Mitglied> $zahler
 * @var \App\Domain\Mitglied|null $quelle
 * @var list<int> $abweichend ids whose address differs from the source's
 * @var bool $anschriftenAbweichend
 * @var list<\App\Domain\FamilienRolle> $rollen
 * @var \App\App\Mitglied\Einbettung $liste the page without a family
 * @var \App\App\Mitglied\Einbettung $hier the page with this family
 * @var bool $darfPflegen
 * @var bool $fokus the heading takes the focus
 * @var string|null $csrf
 */

use App\App\FamilienController;
use App\Domain\Wohnanschrift;

$id = $familie->id;
$anzahl = count($personen);
$csrfFeld = '<input type="hidden" name="_csrf" value="' . e($csrf ?? '') . '">';
?>
<div class="geteilt-detail familie-detail">
    <a class="zurueck" <?= $liste->link($liste->rueckweg()) ?>>Zurück zur Liste</a>
    <div class="detail-kopf">
        <h2 id="familie-titel" tabindex="-1"<?= $fokus ? ' autofocus' : '' ?>><?= e($familie->name) ?></h2>
        <span><?= e(match ($anzahl) { 0 => 'niemand', 1 => '1 Person', default => $anzahl . ' Personen' }) ?></span>
        <a class="knopf knopf-still detail-schliessen" <?= $liste->link($liste->rueckweg()) ?>>Schließen</a>
    </div>

    <?php if ($anzahl === 0): ?>
        <p class="hinweis hinweis-info">In dieser Familie ist noch niemand.<?= $darfPflegen ? ' Fügen Sie unten Personen hinzu oder löschen Sie die leere Familie.' : '' ?></p>
    <?php else: ?>
        <?php if ($anzahl < 2): ?>
            <p class="hinweis hinweis-warnung">Familie zu klein: Zu einer Familie gehören mindestens zwei Personen.</p>
        <?php endif; ?>
        <?php if (count($zahler) !== 1): ?>
            <p class="hinweis hinweis-warnung"><?= $zahler === [] ? 'Die Familie hat keinen Zahler.' : 'Die Familie hat mehrere Zahler.' ?> Den Familienbeitrag zahlt genau eine Person.</p>
        <?php endif; ?>
        <?php if ($anschriftenAbweichend): ?>
            <p class="hinweis hinweis-warnung">Die Anschriften in dieser Familie weichen voneinander ab.<?= $darfPflegen ? ' Unter „Anschriften vereinheitlichen“ übernehmen Sie eine für alle.' : '' ?></p>
        <?php endif; ?>

        <div class="tabelle-rahmen">
            <table class="liste familie-personen">
                <caption>Personen der Familie</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Rolle</th>
                        <th scope="col">Anschrift</th>
                        <?php if ($darfPflegen): ?>
                            <th scope="col">Ändern</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($personen as $i => $person): ?>
                        <?php $anschrift = Wohnanschrift::von($person); ?>
                        <tr>
                            <td class="liste-titel">
                                <a <?= $hier->link($hier->adresse((int) $person->id), 'mitglied') ?>><?= e($person->anzeigeName()) ?></a>
                                <span class="nummer">Nr. <?= e($person->mitgliedsnummer) ?></span>
                            </td>
                            <td data-label="Rolle"><?= e($person->familieRolle?->bezeichnung() ?? 'ohne Rolle') ?></td>
                            <td data-label="Anschrift">
                                <?= $anschrift->istLeer() ? '<span class="gedaempft">keine Anschrift</span>' : e($anschrift->text()) ?>
                                <?php if (in_array((int) $person->id, $abweichend, true) && $quelle !== null): ?>
                                    <span class="etikett etikett-warnung">weicht ab</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($darfPflegen): ?>
                                <td data-label="Ändern" class="familie-aendern">
                                    <form method="post" action="<?= e(FamilienController::aktion($id, 'rolle')) ?>" class="knopfreihe">
                                        <?= $csrfFeld ?>
                                        <input type="hidden" name="mitglied_id" value="<?= e($person->id) ?>">
                                        <label class="nur-lesehilfe" for="rolle-<?= e($i) ?>">Rolle von <?= e($person->anzeigeName()) ?></label>
                                        <select id="rolle-<?= e($i) ?>" name="rolle">
                                            <?php foreach ($rollen as $rolle): ?>
                                                <option value="<?= e($rolle->value) ?>"<?= $rolle === $person->familieRolle ? ' selected' : '' ?>><?= e($rolle->bezeichnung()) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="knopf">Rolle speichern</button>
                                    </form>
                                    <form method="post" action="<?= e(FamilienController::aktion($id, 'entfernen')) ?>">
                                        <?= $csrfFeld ?>
                                        <input type="hidden" name="mitglied_id" value="<?= e($person->id) ?>">
                                        <button type="submit" class="knopf knopf-still">Aus der Familie nehmen<span class="nur-lesehilfe">: <?= e($person->anzeigeName()) ?></span></button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($darfPflegen): ?>
        <?php if ($anzahl > 1): ?>
            <section class="familie-teil" aria-labelledby="zahler-titel">
                <h3 id="zahler-titel">Zahler wechseln</h3>
                <form method="post" action="<?= e(FamilienController::aktion($id, 'zahler')) ?>" class="formular knopfreihe">
                    <?= $csrfFeld ?>
                    <div class="feld">
                        <label for="zahler-neu">Neuer Zahler</label>
                        <select id="zahler-neu" name="mitglied_id">
                            <?php foreach ($personen as $person): ?>
                                <option value="<?= e($person->id) ?>"<?= $person === ($zahler[0] ?? null) ? ' selected' : '' ?>><?= e($person->anzeigeName()) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="feld-hinweis">Wer bisher Zahler war, wird Elternteil. Die Beitragsart ändert sich dabei nicht.</p>
                    </div>
                    <button type="submit" class="knopf">Zahler wechseln</button>
                </form>
            </section>

            <?php if ($anschriftenAbweichend): ?>
                <?php require __DIR__ . '/anschrift.php'; ?>
            <?php endif; ?>
        <?php endif; ?>

        <section class="familie-teil" aria-labelledby="hinzufuegen-titel">
            <h3 id="hinzufuegen-titel">Person hinzufügen</h3>
            <form class="suche" method="post" action="<?= e($hier->adresse()) ?>" role="search"
                  hx-post="<?= e($hier->adresse()) ?>" hx-target="#familie-treffer" hx-swap="innerHTML"
                  hx-sync="this:replace" hx-trigger="input changed delay:300ms from:#familie-suche, submit">
                <?= $csrfFeld ?>
                <div class="feld suche-feld">
                    <label for="familie-suche">Mitglied suchen</label>
                    <input id="familie-suche" name="suche" type="search" value="<?= e($suche) ?>"
                           placeholder="Name, Mitgliedsnummer oder Ort" autocomplete="off">
                </div>
                <div class="filter">
                    <button type="submit" class="knopf">Suchen</button>
                </div>
            </form>
            <div id="familie-treffer" aria-live="polite">
                <?php require __DIR__ . '/treffer.php'; ?>
            </div>
        </section>

        <section class="familie-teil" aria-labelledby="umbenennen-titel">
            <h3 id="umbenennen-titel">Familie umbenennen</h3>
            <form method="post" action="<?= e(FamilienController::aktion($id, 'umbenennen')) ?>" class="formular knopfreihe">
                <?= $csrfFeld ?>
                <div class="feld">
                    <label for="familie-name">Name der Familie</label>
                    <input type="text" id="familie-name" name="name" value="<?= e($familie->name) ?>" maxlength="200" required>
                </div>
                <button type="submit" class="knopf">Umbenennen</button>
            </form>
        </section>

        <?php if ($anzahl === 0): ?>
            <form method="post" action="<?= e(FamilienController::aktion($id, 'loeschen')) ?>" class="knopfreihe">
                <?= $csrfFeld ?>
                <button type="submit" class="knopf knopf-gefahr">Leere Familie löschen</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
