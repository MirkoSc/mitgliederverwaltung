<?php

/**
 * One direct debit that waits for the bank, on the page "Beiträge"
 * (docs/spec/08-beitraege.md section 3, steps 3 and 4): what it holds, its
 * file, "bestätigen" and "verwerfen". Included in a loop by
 * beitraege/einzug.php.
 *
 * @var \App\Domain\Einzug $einzug
 * @var array<int, string|null> $einzugNamen
 * @var string|null $csrf
 */

use App\App\EinzugController;
use App\View\Navigation;

$einzugId = (int) $einzug->id;
$zurueck = Navigation::BEITRAEGE;
$wer = require __DIR__ . '/einzug-wer.php';
?>
<article class="einzug-karte" id="einzug-<?= e($einzugId) ?>" aria-labelledby="einzug-<?= e($einzugId) ?>-titel">
    <h4 id="einzug-<?= e($einzugId) ?>-titel">Einzug Nr. <?= e($einzugId) ?>, fällig am <?= e(datum($einzug->faelligAm)) ?></h4>
    <p><?= $einzug->anzahl === 1 ? '1 Beitrag' : e($einzug->anzahl) . ' Beiträge' ?>, zusammen <strong><?= e(geld($einzug->summeCent)) ?></strong>.
        Erzeugt am <?= e(zeitpunkt($einzug->erstelltAm)) ?><?= $wer($einzugNamen, $einzug->erstelltVon) ?>.
        <a href="<?= e(EinzugController::adresse($einzugId)) ?>">Enthaltene Beiträge ansehen</a></p>
    <?php require __DIR__ . '/einzug-aktionen.php'; ?>
</article>
