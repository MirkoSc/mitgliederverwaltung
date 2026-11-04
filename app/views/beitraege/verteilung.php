<?php

/**
 * "Verteilung der Beiträge" (docs/spec/08-beitraege.md section 2b): what a
 * year's fees bring in - in total, by fee type and by department - from
 * the created fees, or projected while there are none. Markup as in the
 * pattern page /admin/designsystem (list, page browsing); the bar in
 * "Anteil" only repeats the figure next to it.
 *
 * @var \App\Service\Beitraege\Verteilung $verteilung
 * @var int|null $vorjahr
 * @var int|null $folgejahr
 * @var string $zurueck
 */

use App\App\BeitraegeController;

$jahr = $verteilung->jahr;
$anteil = static fn(int $cent): string => '<span class="verteilung-anteil"><meter min="0" max="1000" value="' . e($verteilung->promille($cent)) . '" aria-hidden="true"></meter>'
    . '<span>' . e($verteilung->anteil($cent)) . '</span></span>';
?>
<div class="seitenkopf">
    <h1>Verteilung der Beiträge <?= e($jahr) ?></h1>
    <?php if ($verteilung->hochgerechnet): ?>
        <p>Für <?= e($jahr) ?> sind noch keine Jahresbeiträge erzeugt. Die Zahlen sind hochgerechnet aus den Beitragsarten
            der Mitglieder – so, wie „Beiträge für <?= e($jahr) ?> erzeugen“ sie heute anlegen würde.</p>
    <?php else: ?>
        <p>Aus den erzeugten Jahresbeiträgen <?= e($jahr) ?> – offene, im Einzug und bezahlte zusammen. Mitglieder ohne
            erzeugten Beitrag, etwa Neueintritte, zählen erst nach erneutem Erzeugen mit.</p>
    <?php endif; ?>
</div>

<nav class="blaettern" aria-label="Beitragsjahr">
    <?php if ($vorjahr !== null): ?>
        <a class="knopf" href="<?= e(BeitraegeController::verteilungsAdresse($vorjahr)) ?>">Jahr <?= e($vorjahr) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <p class="blaettern-stand">Beitragsjahr <?= e($jahr) ?></p>
    <?php if ($folgejahr !== null): ?>
        <a class="knopf" href="<?= e(BeitraegeController::verteilungsAdresse($folgejahr)) ?>">Jahr <?= e($folgejahr) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</nav>

<?php if ($verteilung->anzahl === 0): ?>
    <div class="leer">
        <p>Für <?= e($jahr) ?> kommt nichts zusammen: Kein Mitglied hat eine Beitragsart mit einem Betrag über 0 €.</p>
        <p class="knopfreihe"><a class="knopf" href="<?= e($zurueck) ?>">Zurück zu den Beiträgen</a></p>
    </div>
<?php else: ?>
    <p class="verteilung-summe">
        <strong><?= e(geld($verteilung->summeCent)) ?></strong>
        <span><?= $verteilung->hochgerechnet ? 'erwartet' : 'insgesamt' ?> aus
            <?= $verteilung->anzahl === 1 ? '1 Beitrag' : e($verteilung->anzahl) . ' Beiträgen' ?></span>
    </p>

    <section class="verteilung" aria-labelledby="nach-art-titel">
        <h2 id="nach-art-titel">Nach Beitragsart</h2>
        <div class="tabelle-rahmen">
            <table class="liste">
                <thead>
                    <tr>
                        <th scope="col">Beitragsart</th>
                        <th scope="col" class="zahl">Beiträge</th>
                        <th scope="col" class="zahl">Summe</th>
                        <th scope="col">Anteil</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($verteilung->nachArt as $zeile): ?>
                        <tr<?= $zeile->ohne ? ' class="verteilung-ohne"' : '' ?>>
                            <td class="liste-titel"><?= e($zeile->name) ?></td>
                            <td data-label="Beiträge" class="zahl"><?= e($zeile->anzahl) ?></td>
                            <td data-label="Summe" class="zahl"><?= e(geld($zeile->summeCent)) ?></td>
                            <td data-label="Anteil"><?= $anteil($zeile->summeCent) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="liste-titel">Zusammen</td>
                        <td data-label="Beiträge" class="zahl"><?= e($verteilung->anzahl) ?></td>
                        <td data-label="Summe" class="zahl"><?= e(geld($verteilung->summeCent)) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="verteilung" aria-labelledby="nach-abteilung-titel">
        <h2 id="nach-abteilung-titel">Nach Abteilung</h2>
        <p class="feld-hinweis verteilung-regel">Wer in mehreren Abteilungen ist, zahlt einen Beitrag – er wird gleichmäßig
            auf seine Abteilungen geteilt. Ein Familienbeitrag wird zuerst gleichmäßig auf die Personen der Familie
            geteilt (ohne die, die selbst einen Beitrag zahlen), dann auf deren Abteilungen. „Mitglieder“ zählt jede
            Person, die zur Abteilung beiträgt.</p>
        <div class="tabelle-rahmen">
            <table class="liste">
                <thead>
                    <tr>
                        <th scope="col">Abteilung</th>
                        <th scope="col" class="zahl">Mitglieder</th>
                        <th scope="col" class="zahl">Summe</th>
                        <th scope="col">Anteil</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($verteilung->nachAbteilung as $zeile): ?>
                        <tr<?= $zeile->ohne ? ' class="verteilung-ohne"' : '' ?>>
                            <td class="liste-titel"><?= e($zeile->name) ?></td>
                            <td data-label="Mitglieder" class="zahl"><?= e($zeile->anzahl) ?></td>
                            <td data-label="Summe" class="zahl"><?= e(geld($zeile->summeCent)) ?></td>
                            <td data-label="Anteil"><?= $anteil($zeile->summeCent) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="liste-titel">Zusammen</td>
                        <td data-label="Mitglieder" class="zahl"><?= e($verteilung->personen) ?></td>
                        <td data-label="Summe" class="zahl"><?= e(geld($verteilung->summeCent)) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <p class="knopfreihe"><a class="knopf" href="<?= e($zurueck) ?>">Zurück zu den Beiträgen</a></p>
<?php endif; ?>
