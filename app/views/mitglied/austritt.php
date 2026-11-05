<?php

/**
 * "Mitglied ausgetreten" (docs/spec/07-mitglieder.md section 1): leaving
 * date (today by default) and reason, saved as an ordinary save of the
 * member. A date in the future only notes the leaving; until then the
 * person stays a member. Markup from the design system (formular, feld,
 * knopfreihe).
 *
 * @var \App\Domain\Mitglied $mitglied
 * @var array{austrittsdatum: string, austrittsgrund: string} $werte
 * @var string|null $fehler the date could not be read
 * @var string|null $meldung somebody else saved in between
 * @var string|null $csrf
 * @var \App\App\Mitglied\Einbettung $einbettung where the member was opened
 */

use App\App\Mitglied\MitgliedFormular;
use App\App\MitgliedController;

$id = (int) $mitglied->id;
?>
<div class="seitenkopf">
    <h1>Mitglied ausgetreten</h1>
    <p><?= e($mitglied->anzeigeName()) ?>, <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
</div>

<?php if (($meldung ?? null) !== null): ?>
    <p class="hinweis hinweis-warnung" role="alert"><?= e($meldung) ?></p>
<?php endif; ?>

<p>Ab dem Austrittsdatum ist die Person ehemaliges Mitglied: Sie verschwindet aus den Listen, und von ihr wird
    nichts mehr eingezogen. Liegt das Datum in der Zukunft, ist der Austritt nur vermerkt – bis dahin bleibt sie Mitglied.</p>

<form method="post" action="<?= e(MitgliedController::austrittAdresse($id)) ?>" class="formular">
    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
    <input type="hidden" name="version" value="<?= e($mitglied->version) ?>">
    <?= $einbettung->feld() ?>
    <div class="feld feld-kurz">
        <label for="austritt-datum">Austrittsdatum</label>
        <input type="date" id="austritt-datum" name="austrittsdatum" value="<?= e($werte['austrittsdatum']) ?>" required<?= $fehler !== null ? ' aria-invalid="true" aria-describedby="austritt-datum-fehler"' : '' ?>>
        <?php if ($fehler !== null): ?>
            <p class="feld-fehler" id="austritt-datum-fehler"><?= e($fehler) ?></p>
        <?php endif; ?>
    </div>
    <div class="feld feld-breit">
        <label for="austritt-grund">Austrittsgrund</label>
        <input type="text" id="austritt-grund" name="austrittsgrund" value="<?= e($werte['austrittsgrund']) ?>" maxlength="<?= MitgliedFormular::LAENGEN['austrittsgrund'] ?>" list="austritt-gruende" autocomplete="off" aria-describedby="austritt-grund-hilfe">
        <datalist id="austritt-gruende">
            <?php foreach (MitgliedFormular::AUSTRITTSGRUENDE as $grund): ?>
                <option value="<?= e($grund) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="feld-hinweis" id="austritt-grund-hilfe">Freiwillig. Vorschläge: <?= e(implode(', ', MitgliedFormular::AUSTRITTSGRUENDE)) ?>.</p>
    </div>
    <div class="knopfreihe">
        <button type="submit" class="knopf knopf-haupt">Austritt eintragen</button>
        <a class="knopf knopf-still" href="<?= e($einbettung->adresse($id)) ?>">Abbrechen</a>
    </div>
</form>
