<?php

/**
 * The archive of every direct debit at the bottom of the page "Beiträge"
 * (docs/spec/08-beitraege.md section 3): discarded ones too, newest first,
 * each with its stored file and a link to the fees it holds. Runs and
 * files are never deleted. Included by beitraege/seite.php.
 *
 * @var list<\App\Domain\Einzug> $einzuegeAlle
 * @var array<int, string|null> $einzugNamen
 */

use App\App\EinzugController;
use App\Service\Beitraege\Einzuege;

$einzugEtikett = require __DIR__ . '/einzug-etikett.php';
$wer = require __DIR__ . '/einzug-wer.php';
$anzahlEinzuege = count($einzuegeAlle);
?>
<section class="einzug-archiv" id="einzug-archiv" aria-labelledby="einzug-archiv-titel">
    <h2 id="einzug-archiv-titel">Archiv der Einzüge</h2>
    <?php if ($einzuegeAlle === []): ?>
        <div class="leer">
            <p>Noch kein Einzug erzeugt. Jeder Einzug bleibt hier mit seiner Lastschriftdatei stehen – auch ein verworfener.</p>
        </div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="liste">
                <caption><?= $anzahlEinzuege === 1 ? '1 Einzug' : e($anzahlEinzuege) . ' Einzüge' ?>, neueste zuerst, auch verworfene</caption>
                <thead>
                    <tr>
                        <th scope="col">Einzug</th>
                        <th scope="col">Erzeugt</th>
                        <th scope="col">Fällig</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="zahl">Beiträge</th>
                        <th scope="col" class="zahl">Summe</th>
                        <th scope="col" class="nebenspalte">Abgeschlossen</th>
                        <th scope="col">Datei</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($einzuegeAlle as $e): ?>
                        <?php $eId = (int) $e->id; ?>
                        <tr>
                            <td class="liste-titel"><a href="<?= e(EinzugController::adresse($eId)) ?>">Nr. <?= e($eId) ?></a></td>
                            <td data-label="Erzeugt"><?= e(zeitpunkt($e->erstelltAm)) ?><span class="klein gedaempft"><?= $wer($einzugNamen, $e->erstelltVon) ?></span></td>
                            <td data-label="Fällig"><?= e(datum($e->faelligAm)) ?></td>
                            <td data-label="Status"><?= $einzugEtikett($e->status) ?></td>
                            <td data-label="Beiträge" class="zahl"><?= e($e->anzahl) ?></td>
                            <td data-label="Summe" class="zahl"><?= e(geld($e->summeCent)) ?></td>
                            <td data-label="Abgeschlossen" class="nebenspalte">
                                <?php if ($e->bestaetigtAm !== null): ?>
                                    <?= e(zeitpunkt($e->bestaetigtAm)) ?><span class="klein gedaempft"><?= $wer($einzugNamen, $e->bestaetigtVon) ?></span>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                            <td data-label="Datei">
                                <?php if ($e->dateiBlobId !== null): ?>
                                    <a href="<?= e(EinzugController::dateiAdresse($eId)) ?>" download="<?= e(Einzuege::dateiname($e)) ?>" class="einzug-dateiname"><?= e(Einzuege::dateiname($e)) ?></a>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
