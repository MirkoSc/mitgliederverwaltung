<?php

/**
 * The block "Wohnort auf der Karte" at the address of an opened member
 * (issue #106; App\App\Mitglied\DatenReiter). The state is always text -
 * found, not found, not asked yet -, the small map is a bonus on top
 * (public/js/karte.js builds it from data-* attributes; without script and
 * in the Barrierefrei-Modus the text is all there is).
 *
 * Inside the member form, which must not hold a form of its own: the
 * fields and buttons here belong to the form `$wohnortFormular` (attribute
 * form=…) that mitglied/formular.php puts after its own. Without that id
 * (read only, role "Nur lesen") the block has no field and no button.
 *
 * @var \App\Service\Karte\Wohnort $wohnort
 * @var \App\Domain\Mitglied $mitglied
 * @var string|null $wohnortFormular id of the form the buttons post with, null = read only
 */

use App\App\KarteController;
use App\App\WohnortController;
use App\Service\Karte\KachelProxy;
use App\Service\Karte\WohnortZustand;
use App\View\Navigation;

$wohnortFormular ??= null;
$form = $wohnortFormular === null ? '' : ' form="' . e($wohnortFormular) . '"';
$zustand = $wohnort->zustand;
$korrektur = $wohnort->korrektur;
$vorbelegung = $wohnort->vorbelegung();
$anschriftText = $wohnort->anschrift->text();

/** One field of the search address. */
$feld = static function (string $name, string $label, string $wert, int $laenge, string $klasse = '', string $mehr = '') use ($form): string {
    return '<div class="feld' . ($klasse === '' ? '' : ' ' . $klasse) . '">'
        . '<label for="wohnort-' . $name . '">' . e($label) . '</label>'
        . '<input type="text" id="wohnort-' . $name . '" name="such_' . $name . '" value="' . e($wert) . '" maxlength="' . $laenge . '" autocomplete="off"' . $form . ($mehr === '' ? '' : ' ' . $mehr) . '>'
        . '</div>';
};
?>
<section class="wohnort feld-breit" id="<?= e(WohnortController::ANKER) ?>" tabindex="-1" aria-labelledby="wohnort-titel">
    <h4 id="wohnort-titel">Wohnort auf der Karte</h4>

    <?php if ($zustand === WohnortZustand::Gefunden): ?>
        <p class="wohnort-stand"><span class="etikett etikett-ok">gefunden</span> Die Anschrift steht auf der Karte.</p>
        <div class="karte-flaeche wohnort-karte" data-wohnort-karte
             data-lat="<?= e(number_format((float) $wohnort->lat, 6, '.', '')) ?>"
             data-lon="<?= e(number_format((float) $wohnort->lon, 6, '.', '')) ?>"
             data-kacheln="<?= e(KarteController::PFAD_KACHEL . '/{z}/{x}/{y}.png') ?>"
             data-zoom-min="<?= KachelProxy::ZOOM_MIN ?>"
             data-zoom-max="<?= KachelProxy::ZOOM_MAX ?>"
             data-titel="<?= e($anschriftText) ?>"
             role="region"
             aria-label="Kleine Karte mit dem Wohnort <?= e($anschriftText) ?>" hidden></div>
        <p class="wohnort-links"><a href="<?= e(Navigation::KARTE . '?mitglied=' . (int) $mitglied->id) ?>">Auf der großen Karte zeigen</a></p>
    <?php elseif ($zustand === WohnortZustand::NichtGefunden): ?>
        <p class="wohnort-stand"><span class="etikett etikett-warnung">nicht gefunden</span> Die Anschrift wurde auf der Karte nicht gefunden.</p>
    <?php elseif ($zustand === WohnortZustand::Offen): ?>
        <p class="wohnort-stand"><span class="etikett">noch nicht gesucht</span> Wird noch gesucht. Die Suche läuft nach und nach im Hintergrund.</p>
    <?php elseif ($zustand === WohnortZustand::OhneAnschrift): ?>
        <p class="wohnort-stand gedaempft">Ohne PLZ und Ort steht das Mitglied nicht auf der Karte.</p>
    <?php else: ?>
        <p class="wohnort-stand gedaempft">Ehemalige Mitglieder stehen nicht auf der Karte.</p>
    <?php endif; ?>

    <?php if ($korrektur !== null && $wohnort->suchbar()): ?>
        <p class="feld-hinweis">Gesucht wird mit der Such-Anschrift „<?= e($korrektur->text()) ?>“. Die Anschrift des Mitglieds bleibt, wie sie ist.</p>
    <?php endif; ?>

    <?php if ($wohnortFormular !== null && $wohnort->suchbar()): ?>
        <?php if ($zustand === WohnortZustand::Offen): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf" name="aktion" value="<?= e(WohnortController::AKTION_SUCHEN) ?>"<?= $form ?>>Jetzt suchen</button>
                <?php if ($korrektur !== null): ?>
                    <button type="submit" class="knopf knopf-still" name="aktion" value="<?= e(WohnortController::AKTION_ENTFERNEN) ?>"<?= $form ?>>Korrektur entfernen</button>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <?php $offen = $zustand === WohnortZustand::NichtGefunden; ?>
            <details class="wohnort-korrektur"<?= $offen ? ' open' : '' ?>>
                <summary><?= $offen ? 'Mit einer anderen Anschrift suchen' : 'Lage stimmt nicht? Anders suchen' ?></summary>
                <p class="feld-hinweis" id="wohnort-hilfe">Diese Anschrift gilt nur für die Suche auf der Karte. Die Anschrift des Mitglieds bleibt, wie sie ist.</p>
                <div class="wohnort-felder">
                    <?= $feld('strasse', 'Straße und Hausnummer', $vorbelegung->strasse, 150, 'feld-breit', 'aria-describedby="wohnort-hilfe"') ?>
                    <?= $feld('plz', 'PLZ', $vorbelegung->plz, 10, 'feld-kurz', 'inputmode="numeric"') ?>
                    <?= $feld('ort', 'Ort', $vorbelegung->ort, 100) ?>
                </div>
                <p class="knopfreihe">
                    <button type="submit" class="knopf" name="aktion" value="<?= e(WohnortController::AKTION_KORRIGIEREN) ?>"<?= $form ?>>Mit dieser Anschrift suchen</button>
                    <?php if ($korrektur !== null): ?>
                        <button type="submit" class="knopf knopf-still" name="aktion" value="<?= e(WohnortController::AKTION_ENTFERNEN) ?>"<?= $form ?>>Korrektur entfernen</button>
                    <?php endif; ?>
                </p>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>
