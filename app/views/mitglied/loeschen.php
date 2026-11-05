<?php

/**
 * Deleting a member for good (admin only, docs/spec/07-mitglieder.md
 * section 1; 06 section 4 "Rückfragen"), asked twice: step 1 says in whole
 * sentences what goes and what stays ("Weiter"), step 2 "Endgültig
 * löschen?" wants the member number typed in. Only a former member can be
 * deleted - for anyone else the page says why not and nothing more.
 *
 * @var \App\Domain\Mitglied $mitglied
 * @var bool $ehemalig
 * @var int $schritt 1 or 2
 * @var string|null $fehler the typed number did not match (step 2)
 * @var string|null $csrf
 * @var \App\App\Mitglied\Einbettung $einbettung where the member was opened
 */

use App\App\MitgliedController;

$id = (int) $mitglied->id;
$zurueck = $einbettung->adresse($id);
?>
<div class="seitenkopf">
    <h1><?= !$ehemalig ? 'Mitglied löschen' : ($schritt === 2 ? 'Endgültig löschen?' : 'Mitglied löschen?') ?></h1>
    <p><?= e($mitglied->anzeigeName()) ?>, <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
</div>

<?php if (!$ehemalig): ?>
    <div class="hinweis hinweis-warnung" role="alert">
        <p>Löschen lassen sich nur ehemalige Mitglieder.
            <?php if ($mitglied->austrittsdatum !== null): ?>
                Der Austritt ist zum <?= e(datum($mitglied->austrittsdatum)) ?> vermerkt – bis dahin bleibt die Person Mitglied.
            <?php else: ?>
                Tragen Sie zuerst den Austritt ein (Knopf „Mitglied ausgetreten“); ab dem Austrittsdatum ist das Mitglied ehemalig.
            <?php endif; ?>
        </p>
    </div>
    <p class="knopfreihe"><a class="knopf knopf-still" href="<?= e($zurueck) ?>">Zurück zum Mitglied</a></p>
<?php elseif ($schritt === 1): ?>
    <div class="hinweis hinweis-warnung">
        <p>Das Mitglied wird endgültig gelöscht – mit allen Versionen im Verlauf, allen Nachweisen samt Dateien und
            allen Jahresbeiträgen. Das lässt sich nicht rückgängig machen.</p>
        <p>Bereits erzeugte Einzüge behalten ihre eigene Kopie der Angaben.</p>
    </div>
    <p>Ein ehemaliges Mitglied muss nicht gelöscht werden: Es verschwindet ohnehin aus den Listen und bleibt
        mit seinem Verlauf erhalten.</p>
    <p class="knopfreihe">
        <a class="knopf knopf-gefahr" href="<?= e($einbettung->mitRueckweg(MitgliedController::loeschAdresse($id) . '?schritt=2')) ?>">Weiter</a>
        <a class="knopf knopf-still" href="<?= e($zurueck) ?>">Nicht löschen, zurück zum Mitglied</a>
    </p>
<?php else: ?>
    <div class="hinweis hinweis-fehler">
        <p><strong>Das ist der letzte Schritt.</strong> Danach sind Mitglied, Verlauf, Nachweise und Jahresbeiträge
            weg und lassen sich nicht wiederherstellen.</p>
    </div>
    <form method="post" action="<?= e(MitgliedController::loeschAdresse($id)) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <?= $einbettung->feld() ?>
        <div class="feld feld-kurz">
            <label for="loeschen-nummer">Mitgliedsnummer zur Bestätigung eintippen</label>
            <input type="text" id="loeschen-nummer" name="mitgliedsnummer" required inputmode="numeric" autocomplete="off"
                aria-describedby="<?= $fehler !== null ? 'loeschen-nummer-fehler ' : '' ?>loeschen-nummer-hilfe"<?= $fehler !== null ? ' aria-invalid="true"' : '' ?>>
            <?php if ($fehler !== null): ?>
                <p class="feld-fehler" id="loeschen-nummer-fehler"><?= e($fehler) ?></p>
            <?php endif; ?>
            <p class="feld-hinweis" id="loeschen-nummer-hilfe">Die Nummer steht oben neben dem Namen.</p>
        </div>
        <div class="knopfreihe">
            <button type="submit" class="knopf knopf-gefahr">Mitglied endgültig löschen</button>
            <a class="knopf knopf-still" href="<?= e($zurueck) ?>">Nicht löschen, zurück zum Mitglied</a>
        </div>
    </form>
<?php endif; ?>
