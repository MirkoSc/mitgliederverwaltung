<?php

/**
 * "Anschriften vereinheitlichen" (part of familie.php; htmx swaps it on its
 * own when another source is chosen): whose address everybody gets - the
 * payer unless chosen otherwise -, and who gets it - ticked are the persons
 * whose address differs from it; the ticks can be removed. Each person
 * that changes gets a new version, as when saving the form. A hint before
 * the button, no question in the way.
 *
 * @var \App\Domain\Familie $familie
 * @var list<\App\Domain\Mitglied> $personen
 * @var \App\Domain\Mitglied|null $quelle
 * @var list<int> $abweichend
 * @var \App\App\Mitglied\Einbettung $hier
 * @var string|null $csrf
 */

use App\App\FamilienController;
use App\Domain\Wohnanschrift;

if ($quelle === null) {
    return;
}
$ziel = Wohnanschrift::von($quelle);
$andere = array_values(array_filter($personen, static fn($p): bool => $p->id !== $quelle->id));
$vorgewaehlt = count(array_filter($andere, static fn($p): bool => in_array((int) $p->id, $abweichend, true)));
?>
<section class="familie-teil familie-anschrift" id="familie-anschrift" aria-labelledby="anschrift-titel">
    <h3 id="anschrift-titel">Anschriften vereinheitlichen</h3>
    <form method="post" action="<?= e(FamilienController::aktion($familie->id, 'anschrift')) ?>" class="formular" data-anschrift-formular>
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <div class="feld">
            <label for="anschrift-quelle">Anschrift übernehmen von</label>
            <?php /* Another source ticks anew: htmx asks the page for this part. */ ?>
            <select id="anschrift-quelle" name="quelle"
                    hx-post="<?= e($hier->adresse()) ?>" hx-trigger="change" hx-include="closest form"
                    hx-target="#familie-anschrift" hx-swap="outerHTML">
                <?php foreach ($personen as $person): ?>
                    <option value="<?= e($person->id) ?>"<?= $person->id === $quelle->id ? ' selected' : '' ?>><?= e($person->anzeigeName() . ($person->familieRolle !== null ? ' (' . $person->familieRolle->bezeichnung() . ')' : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="knopf knopf-still nur-ohne-skript" formaction="<?= e($hier->adresse()) ?>">Andere Anschrift zeigen</button>
        </div>
        <p class="familie-zielanschrift"><?= $ziel->istLeer() ? 'keine Anschrift eingetragen' : e($ziel->text()) ?></p>

        <fieldset class="feldgruppe">
            <legend>Diese Anschrift bekommen</legend>
            <?php foreach ($andere as $person): ?>
                <?php $bisher = Wohnanschrift::von($person); ?>
                <label class="feld-haken">
                    <input type="checkbox" name="mitglieder[]" value="<?= e($person->id) ?>"<?= in_array((int) $person->id, $abweichend, true) ? ' checked' : '' ?>>
                    <span><?= e($person->anzeigeName()) ?><span class="familie-bisher">bisher: <?= $bisher->istLeer() ? 'keine Anschrift' : e($bisher->text()) ?><?= $bisher->gleich($ziel) ? ' – schon gleich' : '' ?></span></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <p class="hinweis hinweis-info">Straße, PLZ, Ort und Land der Angehakten werden überschrieben. Jede geänderte Person bekommt eine neue Version im Verlauf – dort lässt sich die alte Anschrift zurückholen.</p>
        <div class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt"><span>Anschrift übernehmen (<span data-anschrift-anzahl><?= e($vorgewaehlt) ?></span>)</span></button>
        </div>
    </form>
</section>
