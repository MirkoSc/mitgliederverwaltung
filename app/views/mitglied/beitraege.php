<?php

/**
 * The tab "Beiträge" of an opened member (App\App\Mitglied\BeitraegeReiter,
 * docs/spec/08-beitraege.md section 2): every year with amount, status and
 * notes, newest first. A year opens the page of that fee, where it can be
 * changed. Markup as the pattern "Jahresbeiträge" of /admin/designsystem.
 *
 * @var list<\App\Domain\Jahresbeitrag> $beitraege
 * @var array<int, string|null> $namen
 * @var string $zurueck
 * @var string $seitenAdresse
 */

use App\App\BeitraegeController;
use App\Domain\JahresbeitragStatus;

$statusEtikett = require dirname(__DIR__) . '/beitraege/status-etikett.php';
?>
<?php if ($beitraege === []): ?>
    <div class="leer">
        <p>Noch keine Jahresbeiträge. Sie entstehen auf der Seite Beiträge mit „Beiträge für … erzeugen“.</p>
        <p class="knopfreihe"><a class="knopf" href="<?= e($seitenAdresse) ?>">Zur Seite Beiträge</a></p>
    </div>
<?php else: ?>
    <div class="tabelle-rahmen">
        <table class="liste mitglied-beitraege">
            <caption class="nur-lesehilfe">Jahresbeiträge, neueste zuerst</caption>
            <thead>
                <tr>
                    <th scope="col">Jahr</th>
                    <th scope="col" class="zahl">Betrag</th>
                    <th scope="col">Status</th>
                    <th scope="col">Vermerke</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($beitraege as $beitrag): ?>
                    <tr>
                        <td class="liste-titel"><a href="<?= e(BeitraegeController::adresse((int) $beitrag->id, $zurueck)) ?>" aria-label="Jahresbeitrag <?= e($beitrag->jahr) ?> öffnen"><?= e($beitrag->jahr) ?></a></td>
                        <td data-label="Betrag" class="zahl"><?= e(geld($beitrag->betragCent)) ?></td>
                        <td data-label="Status">
                            <?= $statusEtikett($beitrag->status) ?>
                            <?php if ($beitrag->status === JahresbeitragStatus::Bezahlt): ?>
                                <span class="klein"><?= e(datum($beitrag->bezahltAm)) ?>, <?= $beitrag->zahlweise !== null ? e($beitrag->zahlweise->bezeichnung()) : 'Lastschrift' ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Vermerke"><?php $vermerke = $beitrag->vermerke; require dirname(__DIR__) . '/beitraege/vermerke.php'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
