<?php

/**
 * The block "Wohnort auf der Karte" at the address of an opened member
 * (issues #106, #108; App\App\Mitglied\DatenReiter). The state is always
 * text with its label - found, approximately found, set by hand, not
 * found, not asked yet -, the small map is a bonus on top
 * (public/js/karte.js builds it from data-* attributes; without script and
 * in the Barrierefrei-Modus the text and the fields are all there is).
 *
 * Whoever may edit can also set the point by hand (issue #108): a click on
 * the small map or dragging its marker fills the fields "Breite" and
 * "Länge", "Punkt speichern" saves them. The fields work without script
 * and with the keyboard alone. Without a dot the small map opens at
 * $wohnortMitte (App\Service\Karte\Kartenmitte).
 *
 * Inside the member form, which must not hold a form of its own: the
 * fields and buttons here belong to the form `$wohnortFormular` (attribute
 * form=…) that mitglied/formular.php puts after its own. Without that id
 * (read only, role "Nur lesen") the block has no field and no button.
 *
 * @var \App\Service\Karte\Wohnort $wohnort
 * @var \App\Domain\Mitglied $mitglied
 * @var string|null $wohnortFormular id of the form the buttons post with, null = read only
 * @var \App\Service\Karte\Kartenmitte|null $wohnortMitte where the map opens without a dot
 */

use App\App\KarteController;
use App\App\WohnortController;
use App\Service\Karte\KachelProxy;
use App\Service\Karte\WohnortZustand;
use App\View\Navigation;

$wohnortFormular ??= null;
$wohnortMitte ??= null;
$form = $wohnortFormular === null ? '' : ' form="' . e($wohnortFormular) . '"';
$zustand = $wohnort->zustand;
$korrektur = $wohnort->korrektur;
$vorbelegung = $wohnort->vorbelegung();
$anschriftText = $wohnort->anschrift->text();
$bearbeitbar = $wohnortFormular !== null && $wohnort->suchbar();
$punkt = $zustand->hatPunkt();
// The small map: with a dot, or - for whoever sets one by hand - without.
$mitKarte = $punkt || ($bearbeitbar && $wohnortMitte !== null);
$zahl = static fn(?float $wert): string => $wert === null ? '' : number_format($wert, 6, '.', '');

/** One field of the search address. */
$feld = static function (string $name, string $label, string $wert, int $laenge, string $klasse = '', string $mehr = '') use ($form): string {
    return '<div class="feld' . ($klasse === '' ? '' : ' ' . $klasse) . '">'
        . '<label for="wohnort-' . $name . '">' . e($label) . '</label>'
        . '<input type="text" id="wohnort-' . $name . '" name="such_' . $name . '" value="' . e($wert) . '" maxlength="' . $laenge . '" autocomplete="off"' . $form . ($mehr === '' ? '' : ' ' . $mehr) . '>'
        . '</div>';
};

/** One field of the point set by hand. */
$koordinate = static function (string $name, string $label, string $wert, string $hilfe) use ($form): string {
    return '<div class="feld feld-kurz">'
        . '<label for="wohnort-' . $name . '">' . e($label) . '</label>'
        . '<input type="text" id="wohnort-' . $name . '" name="' . $name . '" value="' . e($wert) . '" maxlength="20" inputmode="decimal" autocomplete="off" aria-describedby="wohnort-punkt-hilfe wohnort-' . $name . '-bereich"' . $form . '>'
        . '<p class="feld-hinweis" id="wohnort-' . $name . '-bereich">' . e($hilfe) . '</p>'
        . '</div>';
};
?>
<section class="wohnort feld-breit" id="<?= e(WohnortController::ANKER) ?>" tabindex="-1" aria-labelledby="wohnort-titel">
    <h4 id="wohnort-titel">Wohnort auf der Karte</h4>

    <?php if ($zustand === WohnortZustand::Gefunden): ?>
        <p class="wohnort-stand"><span class="etikett etikett-ok">gefunden</span> Die Anschrift steht auf der Karte.</p>
    <?php elseif ($zustand === WohnortZustand::Ungefaehr): ?>
        <p class="wohnort-stand"><span class="etikett etikett-warnung">ungefähr</span> Die Straße wurde nicht gefunden, nur PLZ und Ort. Der Punkt steht in der Ortsmitte, nicht am Haus.</p>
    <?php elseif ($zustand === WohnortZustand::VonHand): ?>
        <p class="wohnort-stand"><span class="etikett etikett-info">von Hand</span> Der Punkt wurde von Hand gesetzt. Die automatische Suche ändert ihn nicht.</p>
    <?php elseif ($zustand === WohnortZustand::NichtGefunden): ?>
        <p class="wohnort-stand"><span class="etikett etikett-warnung">nicht gefunden</span> Die Anschrift wurde auf der Karte nicht gefunden.</p>
    <?php elseif ($zustand === WohnortZustand::Offen): ?>
        <p class="wohnort-stand"><span class="etikett">noch nicht gesucht</span> Wird noch gesucht. Die Suche läuft nach und nach im Hintergrund.</p>
    <?php elseif ($zustand === WohnortZustand::OhneAnschrift): ?>
        <p class="wohnort-stand gedaempft">Ohne PLZ und Ort steht das Mitglied nicht auf der Karte.</p>
    <?php else: ?>
        <p class="wohnort-stand gedaempft">Ehemalige Mitglieder stehen nicht auf der Karte.</p>
    <?php endif; ?>

    <?php if ($mitKarte): ?>
        <div class="karte-flaeche wohnort-karte" data-wohnort-karte
             <?php if ($punkt): ?>data-lat="<?= e($zahl($wohnort->lat)) ?>" data-lon="<?= e($zahl($wohnort->lon)) ?>"<?php endif; ?>
             <?php if ($zustand === WohnortZustand::Ungefaehr): ?>data-genauigkeit="ungefaehr"<?php elseif ($zustand === WohnortZustand::VonHand): ?>data-genauigkeit="von_hand"<?php endif; ?>
             <?php if ($wohnortMitte !== null): ?>data-mitte-lat="<?= e($zahl($wohnortMitte->lat)) ?>" data-mitte-lon="<?= e($zahl($wohnortMitte->lon)) ?>" data-mitte-zoom="<?= (int) $wohnortMitte->zoom ?>"<?php endif; ?>
             <?php if ($bearbeitbar): ?>data-setzen="#wohnort-punkt"<?php endif; ?>
             data-kacheln="<?= e(KarteController::PFAD_KACHEL . '/{z}/{x}/{y}.png') ?>"
             data-zoom-min="<?= KachelProxy::ZOOM_MIN ?>"
             data-zoom-max="<?= KachelProxy::ZOOM_MAX ?>"
             data-titel="<?= e($anschriftText) ?>"
             role="region"
             aria-label="Kleine Karte mit dem Wohnort <?= e($anschriftText) ?>" hidden></div>
    <?php endif; ?>
    <?php if ($punkt): ?>
        <p class="wohnort-links"><a href="<?= e(Navigation::KARTE . '?mitglied=' . (int) $mitglied->id) ?>">Auf der großen Karte zeigen</a></p>
    <?php endif; ?>

    <?php if ($korrektur !== null && $wohnort->suchbar()): ?>
        <p class="feld-hinweis">Gesucht wird mit der Such-Anschrift „<?= e($korrektur->text()) ?>“. Die Anschrift des Mitglieds bleibt, wie sie ist.</p>
    <?php endif; ?>

    <?php if ($bearbeitbar): ?>
        <?php if ($zustand === WohnortZustand::Offen): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf" name="aktion" value="<?= e(WohnortController::AKTION_SUCHEN) ?>"<?= $form ?>>Jetzt suchen</button>
                <?php if ($korrektur !== null): ?>
                    <button type="submit" class="knopf knopf-still" name="aktion" value="<?= e(WohnortController::AKTION_ENTFERNEN) ?>"<?= $form ?>>Korrektur entfernen</button>
                <?php endif; ?>
            </p>
        <?php elseif ($zustand !== WohnortZustand::VonHand): ?>
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

        <?php $vonHand = $zustand === WohnortZustand::VonHand; ?>
        <details class="wohnort-korrektur" id="wohnort-punkt"<?= $vonHand ? ' open' : '' ?>>
            <summary><?= $vonHand ? 'Punkt verschieben oder entfernen' : 'Punkt von Hand setzen' ?></summary>
            <p class="feld-hinweis" id="wohnort-punkt-hilfe">Klicken Sie in die kleine Karte oder ziehen Sie den Punkt – oder tragen Sie Breite und Länge ein (zum Beispiel aus einem Kartendienst). Ein Punkt von Hand gilt vor jeder automatischen Suche.</p>
            <div class="wohnort-felder wohnort-koordinaten">
                <?= $koordinate('breite', 'Breite', $zahl($wohnort->lat), 'Zahl von −90 bis 90') ?>
                <?= $koordinate('laenge', 'Länge', $zahl($wohnort->lon), 'Zahl von −180 bis 180') ?>
            </div>
            <p class="wohnort-punkt-stand" role="status" data-wohnort-punkt-stand></p>
            <p class="knopfreihe">
                <button type="submit" class="knopf" name="aktion" value="<?= e(WohnortController::AKTION_PUNKT_SETZEN) ?>"<?= $form ?>>Punkt speichern</button>
                <?php if ($vonHand): ?>
                    <button type="submit" class="knopf knopf-still" name="aktion" value="<?= e(WohnortController::AKTION_PUNKT_ENTFERNEN) ?>"<?= $form ?>>Punkt von Hand entfernen</button>
                <?php endif; ?>
            </p>
        </details>
    <?php endif; ?>
</section>
