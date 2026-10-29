<?php

/**
 * The page of one direct debit (docs/spec/08-beitraege.md section 3): its
 * data, the stored file, "bestätigen"/"verwerfen" while it waits for the
 * bank, and the fees it holds - as the copy taken when the file was made,
 * next to what became of each fee since.
 *
 * @var \App\Domain\Einzug $einzug
 * @var list<\App\Domain\EinzugPosten> $posten
 * @var array<int, \App\Domain\Jahresbeitrag> $beitraege the fees that still exist, by id
 * @var array<int, string|null> $einzugNamen
 * @var bool $dateiVorhanden
 * @var string $hier
 * @var string|null $csrf
 */

use App\App\BeitraegeController;
use App\App\EinzugController;
use App\Domain\EinzugStatus;
use App\Service\Beitraege\Einzuege;
use App\View\Navigation;

$einzugEtikett = require __DIR__ . '/einzug-etikett.php';
$statusEtikett = require __DIR__ . '/status-etikett.php';
$wer = require __DIR__ . '/einzug-wer.php';
$einzugId = (int) $einzug->id;
$zurueck = $hier;
?>
<a class="zurueck" href="<?= e(Navigation::BEITRAEGE) ?>#einzug-archiv">Zurück zu den Beiträgen</a>

<div class="seitenkopf">
    <h1>Einzug Nr. <?= e($einzugId) ?></h1>
    <p><?= $einzugEtikett($einzug->status) ?> fällig am <?= e(datum($einzug->faelligAm)) ?>,
        <?= $einzug->anzahl === 1 ? '1 Beitrag' : e($einzug->anzahl) . ' Beiträge' ?>, zusammen <?= e(geld($einzug->summeCent)) ?></p>
</div>

<dl class="angaben">
    <dt>Erzeugt</dt>
    <dd><?= e(zeitpunkt($einzug->erstelltAm)) ?><?= $wer($einzugNamen, $einzug->erstelltVon) ?></dd>
    <?php if ($einzug->bestaetigtAm !== null): ?>
        <dt><?= $einzug->status === EinzugStatus::Verworfen ? 'Verworfen' : 'Bestätigt' ?></dt>
        <dd><?= e(zeitpunkt($einzug->bestaetigtAm)) ?><?= $wer($einzugNamen, $einzug->bestaetigtVon) ?></dd>
    <?php endif; ?>
    <dt>Lastschriftdatei</dt>
    <dd>
        <?php if ($dateiVorhanden): ?>
            <a href="<?= e(EinzugController::dateiAdresse($einzugId)) ?>" download="<?= e(Einzuege::dateiname($einzug)) ?>" class="einzug-dateiname"><?= e(Einzuege::dateiname($einzug)) ?></a>
        <?php else: ?>
            nicht vorhanden
        <?php endif; ?>
    </dd>
    <?php if ($einzug->msgId !== ''): ?>
        <dt>Nachrichten-Referenz</dt>
        <dd><?= e($einzug->msgId) ?></dd>
    <?php endif; ?>
</dl>

<?php if ($einzug->status === EinzugStatus::Erzeugt): ?>
    <section class="abschnitt" aria-labelledby="einzug-weiter-titel">
        <h2 id="einzug-weiter-titel">Herunterladen, einreichen, bestätigen</h2>
        <?php require __DIR__ . '/einzug-aktionen.php'; ?>
    </section>
<?php elseif ($einzug->status === EinzugStatus::Bestaetigt): ?>
    <p class="hinweis hinweis-info">Kommt eine Lastschrift zurück, öffnen Sie den Beitrag und setzen ihn mit
        „Zurück auf offen setzen“ wieder auf offen. Die Referenz in der Meldung der Bank steht unten in der Spalte „Referenz“.</p>
<?php endif; ?>

<section class="einzug-posten" aria-labelledby="einzug-posten-titel">
    <h2 id="einzug-posten-titel">Beiträge in diesem Einzug</h2>
    <?php if ($posten === []): ?>
        <div class="leer"><p>Zu diesem Einzug sind keine Beiträge gespeichert.</p></div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="liste">
                <caption>So, wie sie in der Lastschriftdatei stehen – mit dem heutigen Stand jedes Beitrags</caption>
                <thead>
                    <tr>
                        <th scope="col">Mitglied</th>
                        <th scope="col">Nr.</th>
                        <th scope="col">Jahr</th>
                        <th scope="col" class="zahl">Betrag</th>
                        <th scope="col" class="nebenspalte">Mandatsreferenz</th>
                        <th scope="col" class="nebenspalte">Referenz</th>
                        <th scope="col">Stand heute</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posten as $p): ?>
                        <?php $beitrag = $beitraege[$p->beitragId] ?? null; ?>
                        <tr>
                            <td class="liste-titel">
                                <?php if ($beitrag !== null): ?>
                                    <a href="<?= e(BeitraegeController::adresse($p->beitragId)) ?>"><?= e($p->name) ?></a>
                                <?php else: ?>
                                    <?= e($p->name) ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Nr."><span class="nummer"><?= e($p->mitgliedsnummer) ?></span></td>
                            <td data-label="Jahr"><?= e($p->jahr) ?></td>
                            <td data-label="Betrag" class="zahl"><?= e(geld($p->betragCent)) ?></td>
                            <td data-label="Mandatsreferenz" class="nebenspalte"><?= e($p->mandatsreferenz) ?></td>
                            <td data-label="Referenz" class="nebenspalte"><?= e($p->endToEndId) ?></td>
                            <td data-label="Stand heute"><?= $beitrag !== null ? $statusEtikett($beitrag->status) : '<span class="gedaempft">gelöscht</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
