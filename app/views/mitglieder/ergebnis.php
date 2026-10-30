<?php

/**
 * The result list: a table on the PC, cards on a phone (design system
 * "Liste"), with the pager. The part htmx swaps while somebody types, sorts
 * or turns the page.
 *
 * Sorting and paging are links: without a script they load the page with
 * the same filters (but without the search text, which is never in a URL);
 * with htmx they post to the page and include the search form. Paging swaps
 * this part only; sorting swaps the whole area like opening a member (list
 * and member beside it), because the search form carries the sort order in
 * its address and must be rendered anew with it.
 *
 * @var \App\Domain\MitgliederFilter $filter
 * @var \App\Domain\MitgliederSeite $ergebnis
 * @var \App\Domain\Mitglied|null $mitglied
 * @var \Closure $url
 * @var \App\App\Mitglied\Einbettung $einbettung swaps the whole area (list and member)
 * @var bool $nurErgebnis the answer to htmx: add the screen reader sentence
 */

use App\Domain\MitgliederSortierung;

// Attributes of a link that swaps the result list only (paging).
$ergebnisLink = static fn(string $ziel, string $swap = 'innerHTML'): string => 'href="' . e($ziel) . '" hx-post="' . e($ziel)
    . '" hx-include="#mitglieder-form" hx-target="#mitglieder-ergebnis" hx-swap="' . $swap . '"';

$kopf = static function (MitgliederSortierung $spalte, string $titel, string $klasse = '') use ($filter, $url, $einbettung): string {
    $sortiert = $filter->sortierung === $spalte;
    $aria = $sortiert ? ' aria-sort="' . ($filter->absteigend ? 'descending' : 'ascending') . '"' : '';
    $link = $einbettung->link($url(['sortierung' => $filter->sortierungNach($spalte), 'seite' => null]), swap: 'outerHTML');

    return '<th scope="col"' . ($klasse === '' ? '' : ' class="' . $klasse . '"') . $aria . '><a ' . $link . '>' . e($titel) . '</a></th>';
};
?>
<?php if ($nurErgebnis): ?>
    <div hx-swap-oob="innerHTML:#suche-stand"><?= e($ergebnis->stand()) ?></div>
<?php endif; ?>

<?php if ($ergebnis->zeilen === []): ?>
    <div class="leer">
        <?php if (!$filter->istGefiltert()): ?>
            <p>Es gibt noch keine Mitglieder.</p>
        <?php else: ?>
            <p>Kein Mitglied passt zu dieser Suche. Prüfen Sie die Schreibweise oder lockern Sie die Filter.</p>
            <?php if (!$filter->mitEhemaligen): ?>
                <p class="klein">Ehemalige Mitglieder sind ausgeblendet. Mit „Ehemalige anzeigen“ erscheinen sie in der Liste.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabelle-rahmen">
        <table class="liste">
            <caption><?= e($ergebnis->gesamt === 1 ? '1 Mitglied' : $ergebnis->gesamt . ' Mitglieder') ?><?= $filter->mitEhemaligen ? '' : ' (ohne Ehemalige)' ?>, sortiert nach <?= e($filter->sortierung->bezeichnung()) ?><?= $filter->absteigend ? ', absteigend' : '' ?></caption>
            <thead>
                <tr>
                    <?= $kopf(MitgliederSortierung::Name, 'Name') ?>
                    <?= $kopf(MitgliederSortierung::Nummer, 'Nr.') ?>
                    <?= $kopf(MitgliederSortierung::Ort, 'Ort', 'nebenspalte') ?>
                    <th scope="col">Abteilung</th>
                    <?= $kopf(MitgliederSortierung::Beitragsart, 'Beitragsart', 'nebenspalte') ?>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ergebnis->zeilen as $zeile): ?>
                    <tr>
                        <?php /* A second click on the open member closes it again. */ ?>
                        <td class="liste-titel"><a <?= $mitglied?->id === $zeile->id ? $einbettung->link($url(['mitglied' => null, 'reiter' => null])) : $einbettung->link($url(['mitglied' => $zeile->id, 'reiter' => null]), 'mitglied') ?><?= $mitglied?->id === $zeile->id ? ' aria-current="true"' : '' ?>><?= e($zeile->name()) ?></a><?php if ($zeile->familienHinweis() !== ''): ?> <span class="klein gedaempft"><?= e($zeile->familienHinweis()) ?></span><?php endif; ?></td>
                        <td data-label="Nr."><span class="nummer"><?= e($zeile->mitgliedsnummer) ?></span></td>
                        <td data-label="Ort" class="nebenspalte"><?= e($zeile->ortAnzeige()) ?></td>
                        <td data-label="Abteilung"><?= e(implode(', ', $zeile->abteilungen)) ?></td>
                        <td data-label="Beitragsart" class="nebenspalte"><?= e($zeile->beitragsart) ?></td>
                        <td data-label="Status"><span class="etikett <?= e($zeile->statusKlasse()) ?>"><?= e($zeile->status()) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php /* Also with one page when a larger size was chosen: "Je Seite" is here, and
             without it nobody could go back from "Alle". */ ?>
    <?php if ($ergebnis->seiten() > 1 || $filter->anzahl !== \App\Domain\MitgliederFilter::SEITENGROESSE): ?>
        <nav class="blaettern" aria-label="Seiten der Liste">
            <?php if ($ergebnis->hatVorherige()): ?>
                <a class="knopf" <?= $ergebnisLink($url(['seite' => $ergebnis->seite - 1 > 1 ? $ergebnis->seite - 1 : null]), 'innerHTML show:top') ?>>Vorherige Seite</a>
            <?php else: ?>
                <span class="knopf" aria-disabled="true">Vorherige Seite</span>
            <?php endif; ?>
            <div class="blaettern-mitte">
                <p class="blaettern-stand">Seite <?= e($ergebnis->seite) ?> von <?= e($ergebnis->seiten()) ?></p>
                <?php /* Belongs to the search form (form attribute): without a script
                         "Übernehmen" posts it, with htmx the change does. */ ?>
                <div class="feld blaettern-anzahl">
                    <label for="filter-anzahl">Je Seite</label>
                    <select id="filter-anzahl" name="anzahl" form="mitglieder-form">
                        <?php foreach (\App\Domain\MitgliederFilter::ANZAHLEN as $anzahl): ?>
                            <option value="<?= e($anzahl === 0 ? 'alle' : $anzahl) ?>"<?= $anzahl === $filter->anzahl ? ' selected' : '' ?>><?= e($anzahl === 0 ? 'Alle' : $anzahl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" form="mitglieder-form" class="knopf knopf-still">Übernehmen</button>
                </div>
            </div>
            <?php if ($ergebnis->hatNaechste()): ?>
                <a class="knopf" <?= $ergebnisLink($url(['seite' => $ergebnis->seite + 1]), 'innerHTML show:top') ?>>Nächste Seite</a>
            <?php else: ?>
                <span class="knopf" aria-disabled="true">Nächste Seite</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
