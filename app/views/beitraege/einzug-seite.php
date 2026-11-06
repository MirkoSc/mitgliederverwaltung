<?php

/**
 * The page of one direct debit (docs/spec/08-beitraege.md section 3): its
 * data, the stored file, "bestätigen"/"verwerfen" while it waits for the
 * bank, and the fees it holds - as the copy taken when the file was made,
 * next to what became of each fee since. A confirmed run takes returned
 * debits of several of its fees at once (#133): ticks in the first column
 * (they belong to the form through form="…"), search and counter from
 * public/js/beitraege-auswahl.js (group "ruecklastschrift").
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
/** Paid by this run's debit - only these can come back. */
$zurueckMoeglich = static fn(?\App\Domain\Jahresbeitrag $b): bool => $einzug->status === EinzugStatus::Bestaetigt
    && $b !== null && $b->einzugId === $einzugId && $b->perLastschriftBezahlt();
$mitAuswahl = array_filter($posten, static fn($p): bool => $zurueckMoeglich($beitraege[$p->beitragId] ?? null)) !== [];
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
<?php elseif ($mitAuswahl): ?>
    <form id="einzug-ruecklastschrift" class="mehrfach-bezahlt" method="post" action="<?= e(EinzugController::adresse($einzugId)) ?>/ruecklastschrift" data-mehrfach-auswahl="ruecklastschrift">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <fieldset class="mehrfach-felder">
            <legend>Rücklastschriften vermerken</legend>
            <p class="feld-hinweis" id="ruecklastschrift-hilfe">Setzen Sie in der Liste unten ein Häkchen bei jedem Beitrag, dessen
                Lastschrift zurückgekommen ist. Die Referenz aus der Meldung der Bank steht in der Spalte „Referenz“. Die Beiträge
                sind danach wieder offen und beim nächsten Einzug nicht vorausgewählt; am Mitglied steht das Jahr der Rücklastschrift.</p>
            <div class="mehrfach-kopf einzug-auswahl-kopf" data-mehrfach-kopf hidden>
                <div class="feld suche-feld">
                    <label for="ruecklastschrift-suche">Mitglied suchen</label>
                    <input id="ruecklastschrift-suche" type="search" autocomplete="off" placeholder="z. B. Müller oder E12-1001" data-mehrfach-suche>
                </div>
                <label class="feld-haken"><input type="checkbox" data-mehrfach-alle> Alle angezeigten auswählen</label>
                <p class="mehrfach-zaehler" role="status" data-mehrfach-zaehler>0 ausgewählt</p>
            </div>
            <div class="mehrfach-angaben einzug-ruecklastschrift-angaben">
                <div class="feld">
                    <label for="ruecklastschrift-vermerk">Vermerk</label>
                    <input type="text" id="ruecklastschrift-vermerk" name="vermerk" maxlength="500" required placeholder="z. B. Konto erloschen">
                </div>
                <button type="submit" class="knopf knopf-haupt" aria-describedby="ruecklastschrift-hilfe">Ausgewählte als Rücklastschrift zurücksetzen</button>
            </div>
        </fieldset>
    </form>
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
                        <?php if ($mitAuswahl): ?>
                            <th scope="col" class="beitrag-auswahl"><span class="nur-lesehilfe">Auswahl</span></th>
                        <?php endif; ?>
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
                        <tr data-suche="<?= e($p->name . ' ' . $p->mitgliedsnummer . ' ' . $p->endToEndId) ?>">
                            <?php if ($mitAuswahl): ?>
                                <td class="beitrag-auswahl">
                                    <?php if ($zurueckMoeglich($beitrag)): ?>
                                        <label class="feld-haken"><input type="checkbox" name="beitrag[]" value="<?= e($p->beitragId) ?>" form="einzug-ruecklastschrift" data-mehrfach-haken="ruecklastschrift">
                                            <span class="nur-lesehilfe"><?= e($p->name) ?>: Rücklastschrift <?= e($p->jahr) ?></span></label>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
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
        <?php if ($mitAuswahl): ?>
            <input type="hidden" name="<?= e(EinzugController::VOLLSTAENDIG) ?>" value="1" form="einzug-ruecklastschrift">
        <?php endif; ?>
    <?php endif; ?>
</section>
