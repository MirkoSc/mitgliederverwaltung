<?php

/**
 * The matches of "Person hinzufügen" (part of familie.php, also swapped
 * on its own by htmx while somebody types): each with a role and a button.
 * A person in another family moves - said beside the name, not blocked.
 *
 * @var \App\Domain\Familie $familie
 * @var string $suche
 * @var list<\App\Domain\Mitglied> $treffer
 * @var array<int, string> $andereFamilien names of the families the matches are in
 * @var list<\App\Domain\FamilienRolle> $rollen
 * @var string|null $csrf
 */

use App\App\FamilienController;
use App\Domain\FamilienRolle;

?>
<?php if ($suche === ''): ?>
    <p class="klein gedaempft">Suchen Sie das Mitglied, das zur Familie gehört.</p>
<?php elseif ($treffer === []): ?>
    <p>Kein Mitglied außerhalb dieser Familie passt zu „<?= e($suche) ?>“. Prüfen Sie die Schreibweise; ehemalige Mitglieder werden nicht gesucht.</p>
<?php else: ?>
    <ul class="familie-treffer">
        <?php foreach ($treffer as $i => $mitglied): ?>
            <li>
                <form method="post" action="<?= e(FamilienController::aktion($familie->id, 'hinzufuegen')) ?>" class="knopfreihe">
                    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                    <input type="hidden" name="mitglied_id" value="<?= e($mitglied->id) ?>">
                    <span class="familie-treffer-name">
                        <strong><?= e($mitglied->anzeigeName()) ?></strong>
                        <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span>
                        <?php if ($mitglied->familieId !== null): ?>
                            <span class="etikett etikett-info">jetzt in <?= e($andereFamilien[$mitglied->familieId] ?? 'einer anderen Familie') ?></span>
                        <?php endif; ?>
                    </span>
                    <label class="nur-lesehilfe" for="treffer-rolle-<?= e($i) ?>">Rolle von <?= e($mitglied->anzeigeName()) ?></label>
                    <select id="treffer-rolle-<?= e($i) ?>" name="rolle">
                        <?php foreach ($rollen as $rolle): ?>
                            <option value="<?= e($rolle->value) ?>"<?= $rolle === FamilienRolle::Kind ? ' selected' : '' ?>><?= e($rolle->bezeichnung()) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="knopf">Hinzufügen<span class="nur-lesehilfe">: <?= e($mitglied->anzeigeName()) ?></span></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
