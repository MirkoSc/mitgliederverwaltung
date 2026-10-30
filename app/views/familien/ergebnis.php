<?php

/**
 * The list of the families: a table on the PC, cards on a phone (design
 * system "Liste"). Where the addresses within a family differ, a label
 * says so in words. A second click on the opened family closes it.
 *
 * @var list<\App\Domain\FamilienZeile> $zeilen
 * @var \App\App\Mitglied\Einbettung $liste
 * @var \App\Domain\Familie|null $familie
 * @var string $text
 * @var bool $nurErgebnis the answer to htmx: update the screen reader sentence
 */

$stand = count($zeilen) === 1 ? '1 Familie gefunden' : count($zeilen) . ' Familien gefunden';
?>
<?php if ($nurErgebnis): ?>
    <div hx-swap-oob="innerHTML:#familien-stand"><?= e($stand) ?></div>
<?php endif; ?>

<?php if ($zeilen === []): ?>
    <div class="leer">
        <?php if ($text === ''): ?>
            <p>Es gibt noch keine Familien. Legen Sie unten eine an oder wählen Sie bei einem Mitglied im Reiter „Familie“ eine aus.</p>
        <?php else: ?>
            <p>Keine Familie passt zu dieser Suche. Gesucht wird im Namen der Familie und in Vor- und Nachnamen ihrer Personen.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabelle-rahmen">
        <table class="liste familien-liste">
            <caption><?= e(count($zeilen) === 1 ? '1 Familie' : count($zeilen) . ' Familien') ?>, nach Name</caption>
            <thead>
                <tr>
                    <th scope="col">Familie</th>
                    <th scope="col">Zahler</th>
                    <th scope="col" class="nebenspalte">Personen</th>
                    <th scope="col">Anschrift</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($zeilen as $zeile): ?>
                    <?php $geoeffnet = $familie?->id === $zeile->id; ?>
                    <tr>
                        <td class="liste-titel"><a <?= $geoeffnet ? $liste->link($liste->rueckweg()) . ' aria-current="true"' : $liste->link($liste->adresse(null, null, ['familie' => $zeile->id]), 'familie') ?>><?= e($zeile->name) ?></a></td>
                        <td data-label="Zahler"><?= $zeile->zahler === [] ? '<span class="gedaempft">kein Zahler</span>' : e($zeile->zahlerText()) ?></td>
                        <td data-label="Personen" class="nebenspalte"><?= e($zeile->anzahlText()) ?></td>
                        <td data-label="Anschrift">
                            <?php if ($zeile->anschriftenAbweichend): ?>
                                <span class="etikett etikett-warnung"><span aria-hidden="true">⚠ </span>Anschriften weichen ab</span>
                            <?php else: ?>
                                <span class="gedaempft">einheitlich</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
