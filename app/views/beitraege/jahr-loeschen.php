<?php

/**
 * The question before the fees of a year are deleted
 * (docs/spec/08-beitraege.md section 2a, #90; 06 section 4 "Rückfragen"):
 * in whole sentences, what goes - fees, paid ones, direct debits and their
 * files - and that a file may already be at the bank. Deleted only once
 * the confirmation word is typed (checked by the server).
 *
 * @var \App\Service\Beitraege\BeitragsjahrUmfang $umfang
 * @var string $aktion
 * @var string $zurueck
 * @var string $wort
 * @var string|null $csrf
 */

use App\App\EinzugController;

$jahr = $umfang->jahr;
$anzahlEinzuege = count($umfang->einzuege);
?>
<div class="seitenkopf">
    <h1>Beiträge <?= e($jahr) ?> löschen?</h1>
</div>

<?php if ($umfang->leer()): ?>
    <div class="leer">
        <p>Für <?= e($jahr) ?> gibt es keine Jahresbeiträge und keine Einzüge – es gibt nichts zu löschen.</p>
        <p class="knopfreihe"><a class="knopf" href="<?= e($zurueck) ?>">Zurück zu den Beiträgen</a></p>
    </div>
<?php else: ?>
    <div class="hinweis hinweis-warnung">
        <p><strong>Endgültig gelöscht werden:</strong></p>
        <ul class="jahr-loeschen-umfang">
            <li><?= $umfang->anzahl === 1 ? '1 Jahresbeitrag' : e($umfang->anzahl) . ' Jahresbeiträge' ?> für <?= e($jahr) ?>
                über zusammen <?= e(geld($umfang->summeCent)) ?>, davon <?= e($umfang->bezahlt) ?> bezahlt – mit Bezahlt-Status und allen Vermerken.</li>
            <?php if ($anzahlEinzuege > 0): ?>
                <li><?= $anzahlEinzuege === 1 ? '1 Einzug' : e($anzahlEinzuege) . ' Einzüge' ?> dieses Jahres mit
                    <?= $umfang->dateien() === 1 ? 'seiner Lastschriftdatei' : 'ihren ' . e($umfang->dateien()) . ' Lastschriftdateien' ?>:
                    <?= e(implode(', ', array_map(
                        static fn($einzug): string => sprintf('Nr. %d (fällig am %s, %s)', (int) $einzug->id, datum($einzug->faelligAm), $einzug->status->bezeichnung()),
                        $umfang->einzuege,
                    ))) ?>.</li>
            <?php else: ?>
                <li>Einzüge gibt es für <?= e($jahr) ?> keine.</li>
            <?php endif; ?>
        </ul>
        <p>Das lässt sich nicht rückgängig machen. Danach können Sie die Beiträge für <?= e($jahr) ?> neu erzeugen.</p>
    </div>

    <?php if ($umfang->hatLastschriftdatei()): ?>
        <div class="hinweis hinweis-fehler">
            <p><strong>Die Bank hat die Datei eventuell schon erhalten.</strong> Wurden die Beiträge schon eingezogen,
                könnte ein neuer Einzug sie ein zweites Mal abbuchen. Prüfen Sie das vor dem nächsten Einzug.</p>
        </div>
    <?php endif; ?>

    <?php if ($umfang->gemischt !== []): ?>
        <p class="jahr-loeschen-text">
            <?php foreach ($umfang->gemischt as $einzug): ?>
                <a href="<?= e(EinzugController::adresse((int) $einzug->id)) ?>">Einzug Nr. <?= e($einzug->id) ?></a>
            <?php endforeach; ?>
            <?= count($umfang->gemischt) === 1 ? 'enthält' : 'enthalten' ?> auch Beiträge anderer Jahre und
            <?= count($umfang->gemischt) === 1 ? 'bleibt' : 'bleiben' ?> deshalb im Archiv; die Beiträge aus <?= e($jahr) ?>
            stehen dort danach als gelöscht.</p>
    <?php endif; ?>

    <form method="post" action="<?= e($aktion) ?>" class="formular jahr-loeschen-formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <input type="hidden" name="jahr" value="<?= e($jahr) ?>">
        <div class="feld feld-kurz">
            <label for="bestaetigung">Zum Bestätigen „<?= e($wort) ?>“ eintippen</label>
            <input type="text" id="bestaetigung" name="bestaetigung" required autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="bestaetigung-hilfe">
            <p class="feld-hinweis" id="bestaetigung-hilfe">Groß- oder Kleinschreibung ist egal.</p>
        </div>
        <div class="knopfreihe">
            <button type="submit" class="knopf knopf-gefahr">Beiträge <?= e($jahr) ?> endgültig löschen</button>
            <a class="knopf knopf-still" href="<?= e($zurueck) ?>">Nicht löschen, zurück zu den Beiträgen</a>
        </div>
    </form>
<?php endif; ?>
