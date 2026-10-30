<?php

/**
 * The tab "Beiträge" of an opened member (App\App\Mitglied\BeitraegeReiter,
 * docs/spec/08-beitraege.md section 2): every year with amount, status and
 * notes, newest first. With $darfVerwalten a year opens the page of that
 * fee, where it can be changed; "Nur lesen" sees the same table without
 * links (issue #87). Markup as the pattern "Jahresbeiträge" of
 * /admin/designsystem.
 *
 * @var bool $darfVerwalten
 * @var list<\App\Domain\Jahresbeitrag> $beitraege
 * @var array<int, string|null> $namen
 * @var int $mitgliedId
 * @var \App\App\Mitglied\Einbettung|null $einbettung the page the member is shown on (MitgliedAnsicht)
 * @var string $seitenAdresse
 */

use App\App\BeitraegeController;
use App\App\Mitglied\Einbettung;
use App\Domain\JahresbeitragStatus;

// The page of a fee leads back to this member on this tab.
$zurueck = ($einbettung ?? Einbettung::mitgliederSeite())->adresse($mitgliedId, 'beitraege');
$statusEtikett = require dirname(__DIR__) . '/beitraege/status-etikett.php';
?>
<?php if ($beitraege === []): ?>
    <div class="leer">
        <p>Noch keine Jahresbeiträge. Sie entstehen auf der Seite Beiträge mit „Beiträge für … erzeugen“.</p>
        <?php if ($darfVerwalten): ?>
            <p class="knopfreihe"><a class="knopf" href="<?= e($seitenAdresse) ?>">Zur Seite Beiträge</a></p>
        <?php endif; ?>
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
                        <td class="liste-titel"><?php if ($darfVerwalten): ?><a href="<?= e(BeitraegeController::adresse((int) $beitrag->id, $zurueck)) ?>" aria-label="Jahresbeitrag <?= e($beitrag->jahr) ?> öffnen"><?= e($beitrag->jahr) ?></a><?php else: ?><?= e($beitrag->jahr) ?><?php endif; ?></td>
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
