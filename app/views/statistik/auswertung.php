<?php

/**
 * One evaluation of the page "Statistik" (docs/spec/09-listen-und-exporte.md
 * section 7): title, how it is counted, and the table. The table is the
 * source: public/js/statistik.js reads data-diagramm, the series (th
 * data-reihe/data-farbe) and the raw values (td data-wert) and draws the
 * chart above it, then folds the table away. Without JavaScript and in the
 * Barrierefrei-Modus only the table shows. Markup of the list as on
 * /admin/designsystem.
 *
 * @var \App\Service\Statistik\Auswertung $auswertung
 */

$a = $auswertung;
$mehrereReihen = count($a->reihen) > 1;
$mitAnteil = $a->zusammen !== null;
$wert = static fn(int $w): string => $a->geld ? geld($w) : number_format($w, 0, ',', '.');
$zelle = static fn(int $w, string $label, bool $roh = true): string => '<td class="zahl" data-label="' . e($label) . '"'
    . ($roh ? ' data-wert="' . e($w) . '"' : '') . '>' . e($wert($w)) . '</td>';
$zeile = static function (\App\Service\Statistik\AuswertungsZeile $z, bool $istSumme) use ($a, $mehrereReihen, $mitAnteil, $zelle): string {
    $html = '<td class="liste-titel">' . e($z->name) . '</td>';
    if ($a->anzahlTitel !== null) {
        $html .= '<td class="zahl" data-label="' . e($a->anzahlTitel) . '">' . e($z->anzahl ?? '') . '</td>';
    }
    foreach (array_keys($a->reihen) as $i => $reihe) {
        $html .= $zelle($z->werte[$i] ?? 0, $reihe, !$istSumme);
    }
    if ($mehrereReihen) {
        $html .= $zelle($z->summe(), 'Zusammen', false);
    }
    if ($a->zusatzTitel !== null) {
        $html .= '<td class="zahl" data-label="' . e($a->zusatzTitel) . '">' . e($z->zusatz ?? '') . '</td>';
    }
    if ($mitAnteil) {
        $html .= '<td class="zahl" data-label="Anteil">' . e($a->anteil($z->summe())) . '</td>';
    }

    return $html;
};
?>
<section class="statistik-abschnitt" id="<?= e($a->id) ?>" aria-labelledby="titel-<?= e($a->id) ?>">
    <h2 id="titel-<?= e($a->id) ?>"><?= e($a->titel) ?></h2>
    <?php if ($a->erklaerung !== ''): ?>
        <p class="feld-hinweis statistik-erklaerung"><?= e($a->erklaerung) ?></p>
    <?php endif; ?>

    <?php if ($a->istLeer()): ?>
        <p class="gedaempft">Hier gibt es noch nichts zu zählen.</p>
    <?php else: ?>
        <details class="statistik-tabelle" open>
            <summary>Zahlen als Tabelle</summary>
            <div class="tabelle-rahmen">
                <table class="liste" data-diagramm="<?= e($a->diagramm->value) ?>" data-einheit="<?= $a->geld ? 'cent' : 'anzahl' ?>" data-titel="<?= e($a->titel) ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?= e($a->merkmal) ?></th>
                            <?php if ($a->anzahlTitel !== null): ?>
                                <th scope="col" class="zahl"><?= e($a->anzahlTitel) ?></th>
                            <?php endif; ?>
                            <?php foreach ($a->reihen as $reihe => $farbe): ?>
                                <th scope="col" class="zahl" data-reihe="<?= e($reihe) ?>" data-farbe="<?= e($farbe) ?>"><?= e($reihe) ?></th>
                            <?php endforeach; ?>
                            <?php if ($mehrereReihen): ?>
                                <th scope="col" class="zahl">Zusammen</th>
                            <?php endif; ?>
                            <?php if ($a->zusatzTitel !== null): ?>
                                <th scope="col" class="zahl"><?= e($a->zusatzTitel) ?></th>
                            <?php endif; ?>
                            <?php if ($mitAnteil): ?>
                                <th scope="col" class="zahl">Anteil</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($a->zeilen as $z): ?>
                            <tr<?= $z->ohne ? ' class="statistik-ohne" data-ohne' : '' ?>><?= $zeile($z, false) ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($a->zusammen !== null): ?>
                        <tfoot>
                            <tr><?= $zeile($a->zusammen, true) ?></tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </details>
    <?php endif; ?>
</section>
