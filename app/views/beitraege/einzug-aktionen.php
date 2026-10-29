<?php

/**
 * Steps 3 and 4 of a direct debit that waits for the bank
 * (docs/spec/08-beitraege.md section 3): download the stored file, then
 * "bestätigen" or "verwerfen" - each behind a tick in whole sentences
 * (06 section 4, "Rückfragen"), because both change every fee of the run.
 *
 * @var \App\Domain\Einzug $einzug
 * @var string $zurueck where the forms return to
 * @var string|null $csrf
 */

use App\App\EinzugController;
use App\Service\Beitraege\Einzuege;

$einzugId = (int) $einzug->id;
$beitraegeText = $einzug->anzahl === 1 ? 'der Beitrag' : 'alle ' . $einzug->anzahl . ' Beiträge';
$versteckt = '<input type="hidden" name="_csrf" value="' . e($csrf ?? '') . '"><input type="hidden" name="zurueck" value="' . e($zurueck) . '">';
?>
<div class="einzug-schritte">
    <div class="einzug-datei">
        <p><a class="knopf knopf-haupt" href="<?= e(EinzugController::dateiAdresse($einzugId)) ?>" download="<?= e(Einzuege::dateiname($einzug)) ?>">Lastschriftdatei herunterladen</a></p>
        <p class="feld-hinweis">Reichen Sie die Datei im Online-Banking als SEPA-Lastschrift ein. Sie bleibt hier gespeichert
            und ist bei jedem Herunterladen dieselbe.</p>
    </div>
    <form class="einzug-entscheidung" method="post" action="<?= e(EinzugController::adresse($einzugId)) ?>/bestaetigen">
        <?= $versteckt ?>
        <p><strong>Hat die Bank eingezogen?</strong> Dann bestätigen Sie den Einzug: <?= e($beitraegeText) ?> gelten als
            bezahlt zum <?= e(datum($einzug->faelligAm)) ?>.</p>
        <label class="feld-haken"><input type="checkbox" name="sicher" value="1" required> Ja, die Bank hat diesen Einzug ausgeführt.</label>
        <p><button type="submit" class="knopf">Einzug bestätigen</button></p>
    </form>
    <form class="einzug-entscheidung" method="post" action="<?= e(EinzugController::adresse($einzugId)) ?>/verwerfen">
        <?= $versteckt ?>
        <p><strong>Datei nicht eingereicht?</strong> Dann verwerfen Sie den Einzug: <?= e($beitraegeText) ?> sind wieder
            offen. Einzug und Datei bleiben im Archiv.</p>
        <label class="feld-haken"><input type="checkbox" name="sicher" value="1" required> Ja, die Datei ging nicht an die Bank.</label>
        <p><button type="submit" class="knopf knopf-gefahr">Einzug verwerfen</button></p>
    </form>
</div>
