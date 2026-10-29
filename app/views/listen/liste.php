<?php

/**
 * One list of the page "Listen" (docs/spec/09-listen-und-exporte.md,
 * 06-oberflaeche.md sections 2 and 3): the selection on top, the list's
 * filters, table (cards on a phone), paging and the export. Markup as in
 * the pattern page /admin/designsystem.
 *
 * @var string $title
 * @var list<\App\Listen\Liste> $listen
 * @var \App\Listen\ListenAnsicht $ansicht
 * @var int $seite
 * @var list<\App\Listen\ListenZeile> $zeilen
 * @var list<\App\Service\Export\ExportFormat> $formate
 * @var string $pfad
 * @var string $mitgliedPfad
 * @var string|null $csrf
 */

use App\Listen\FilterArt;
use App\Listen\ListenFilter;
use App\Listen\Spalte;
use App\Listen\SpaltenTyp;

$liste = $ansicht->liste;
$ergebnis = $ansicht->ergebnis;
$anzahl = count($ansicht->zeilen);
$seiten = $ansicht->seitenzahl();
$hatAktion = array_filter($ansicht->zeilen, static fn(\App\Listen\ListenZeile $z): bool => $z->aktion !== null) !== [];

/**
 * The address of this list with the current filters and order, changed by
 * $aenderungen (null removes a value). Empty, unchecked and default values
 * are left out.
 *
 * @param array<string, string|int|bool|null> $aenderungen
 */
$standards = [];
foreach ($liste->filter() as $f) {
    $standards[$f->name] = $f->standard;
}
$link = static function (array $aenderungen = []) use ($pfad, $ansicht, $standards): string {
    $werte = [
        ...$ansicht->filter,
        'sortierung' => $ansicht->sortierung,
        'richtung' => $ansicht->absteigend ? 'ab' : null,
        ...$aenderungen,
    ];
    $abfrage = [];
    foreach ($werte as $name => $wert) {
        if ($wert === null || $wert === false || $wert === '' || $wert === ($standards[$name] ?? null)) {
            continue;
        }
        $abfrage[$name] = $wert === true ? '1' : (string) $wert;
    }

    return $pfad . ($abfrage === [] ? '' : '?' . http_build_query($abfrage));
};

/** The cell as shown: dates and amounts in German form. */
$zelle = static function (Spalte $spalte, string|int|\DateTimeInterface|null $wert): string {
    if ($wert === null || $wert === '') {
        return '';
    }

    return match ($spalte->typ) {
        SpaltenTyp::Datum => datum($wert instanceof \DateTimeInterface ? $wert : (string) $wert),
        SpaltenTyp::Geld => is_int($wert) ? geld($wert) : (string) $wert,
        default => $wert instanceof \DateTimeInterface ? datum($wert) : (string) $wert,
    };
};

/** The filter values worth sending along with a form (checked boxes, set values). */
$versteckt = static function () use ($ansicht): array {
    $felder = [];
    foreach ($ansicht->filter as $name => $wert) {
        if ($wert !== false && $wert !== '') {
            $felder[$name] = $wert === true ? '1' : (string) $wert;
        }
    }

    return $felder;
};
?>
<h1>Listen</h1>

<nav class="reiter" aria-label="Listen">
    <ul>
        <?php foreach ($listen as $eintrag): ?>
            <li><a href="/app/listen/<?= e($eintrag->schluessel()) ?>"<?= $eintrag->schluessel() === $liste->schluessel() ? ' aria-current="page"' : '' ?>><?= e($eintrag->titel()) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>

<section class="liste-seite" aria-labelledby="liste-titel">
    <h2 id="liste-titel"><?= e($liste->titel()) ?></h2>
    <p><?= e($liste->beschreibung()) ?></p>

    <?php foreach ($ergebnis->hinweise as $hinweis): ?>
        <p class="hinweis hinweis-warnung"><?= e($hinweis) ?></p>
    <?php endforeach; ?>

    <?php if ($liste->filter() !== []): ?>
        <form class="suche" method="get" action="<?= e($pfad) ?>">
            <?php if ($ansicht->sortierung !== null): ?>
                <input type="hidden" name="sortierung" value="<?= e($ansicht->sortierung) ?>">
                <?php if ($ansicht->absteigend): ?><input type="hidden" name="richtung" value="ab"><?php endif; ?>
            <?php endif; ?>
            <div class="filter">
                <?php foreach ($liste->filter() as $filter): ?>
                    <?php /** @var ListenFilter $filter */ $wert = $ansicht->filter[$filter->name]; $id = 'filter-' . $filter->name; ?>
                    <?php if ($filter->art === FilterArt::Haken): ?>
                        <label class="feld-haken"><input type="checkbox" name="<?= e($filter->name) ?>" value="1"<?= $wert === true ? ' checked' : '' ?>> <?= e($filter->label) ?></label>
                    <?php else: ?>
                        <div class="feld">
                            <label for="<?= e($id) ?>"><?= e($filter->label) ?></label>
                            <?php if ($filter->art === FilterArt::Auswahl): ?>
                                <select id="<?= e($id) ?>" name="<?= e($filter->name) ?>">
                                    <?php foreach ($filter->optionen as $wertOption => $bezeichnung): ?>
                                        <option value="<?= e((string) $wertOption) ?>"<?= (string) $wertOption === (string) $wert ? ' selected' : '' ?>><?= e($bezeichnung) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($filter->art === FilterArt::Jahr): ?>
                                <input id="<?= e($id) ?>" type="number" name="<?= e($filter->name) ?>" value="<?= e((string) $wert) ?>" min="1900" max="2200" inputmode="numeric">
                            <?php else: ?>
                                <input id="<?= e($id) ?>" type="date" name="<?= e($filter->name) ?>" value="<?= e((string) $wert) ?>">
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button type="submit" class="knopf">Anzeigen</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($ergebnis->navigation !== null): ?>
        <nav class="blaettern" aria-label="Zeitraum">
            <a class="knopf" href="<?= e($link($ergebnis->navigation->zurueck + ['seite' => null])) ?>">Zurück</a>
            <p class="blaettern-stand"><?= e($ergebnis->navigation->titel) ?></p>
            <a class="knopf" href="<?= e($link($ergebnis->navigation->vor + ['seite' => null])) ?>">Vor</a>
        </nav>
    <?php endif; ?>

    <?php if ($anzahl === 0): ?>
        <div class="leer">
            <p>Für diese Auswahl gibt es keine Einträge.</p>
            <?php if ($liste->filter() !== []): ?>
                <p class="knopfreihe"><a class="knopf" href="<?= e($pfad) ?>">Filter zurücksetzen</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="liste">
                <caption><?= $anzahl === 1 ? '1 Eintrag' : e((string) $anzahl) . ' Einträge' ?><?= $ansicht->sortierung !== null ? ', sortiert' : '' ?></caption>
                <thead>
                    <tr>
                        <?php foreach ($ergebnis->spalten as $spalte): ?>
                            <?php
                            $sortiert = $spalte->schluessel === $ansicht->sortierung;
                            $richtung = $sortiert && !$ansicht->absteigend ? 'ab' : null;
                            ?>
                            <th scope="col"<?= $sortiert ? ' aria-sort="' . ($ansicht->absteigend ? 'descending' : 'ascending') . '"' : '' ?><?= $spalte->nebenspalte ? ' class="nebenspalte"' : '' ?>><a href="<?= e($link(['sortierung' => $spalte->schluessel, 'richtung' => $richtung, 'seite' => null])) ?>"><?= e($spalte->titel) ?></a></th>
                        <?php endforeach; ?>
                        <?php if ($hatAktion): ?><th scope="col">Vermerk</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zeilen as $zeile): ?>
                        <tr>
                            <?php foreach ($ergebnis->spalten as $nr => $spalte): ?>
                                <?php
                                $text = $zelle($spalte, $zeile->werte[$spalte->schluessel] ?? null);
                                $klassen = array_filter([
                                    $nr === 0 ? 'liste-titel' : null,
                                    $spalte->typ === SpaltenTyp::Zahl || $spalte->typ === SpaltenTyp::Geld ? 'zahl' : null,
                                    $spalte->nebenspalte ? 'nebenspalte' : null,
                                ]);
                                ?>
                                <td<?= $nr > 0 ? ' data-label="' . e($spalte->titel) . '"' : '' ?><?= $klassen === [] ? '' : ' class="' . e(implode(' ', $klassen)) . '"' ?>>
                                    <?php if ($nr === 0 && $zeile->mitgliedId !== null): ?>
                                        <a href="<?= e($mitgliedPfad . '?mitglied=' . $zeile->mitgliedId) ?>"><?= e($text) ?></a>
                                    <?php else: ?>
                                        <?= e($text) ?>
                                    <?php endif; ?>
                                    <?php if ($nr === 0 && $zeile->marke !== null): ?>
                                        <span class="etikett etikett-info"><?= e($zeile->marke) ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($hatAktion): ?>
                                <td data-label="Vermerk">
                                    <?php if ($zeile->aktion !== null): ?>
                                        <form method="post" action="<?= e($zeile->aktion->pfad) ?>">
                                            <input type="hidden" name="_csrf" value="<?= e((string) $csrf) ?>">
                                            <?php foreach ($zeile->aktion->felder as $name => $wert): ?>
                                                <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="knopf<?= $zeile->aktion->zurueckgenommen ? '' : ' knopf-haupt' ?>"><?= e($zeile->aktion->beschriftung) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($seiten > 1): ?>
            <nav class="blaettern" aria-label="Seiten der Liste">
                <?php if ($seite > 1): ?>
                    <a class="knopf" href="<?= e($link(['seite' => $seite - 1])) ?>">Vorherige Seite</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <p class="blaettern-stand">Seite <?= e($seite) ?> von <?= e($seiten) ?>, je Seite <?= e(\App\Listen\ListenAnsicht::PRO_SEITE) ?> Einträge</p>
                <?php if ($seite < $seiten): ?>
                    <a class="knopf" href="<?= e($link(['seite' => $seite + 1])) ?>">Nächste Seite</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <section class="listen-export" aria-labelledby="export-titel">
            <h3 id="export-titel">Liste exportieren</h3>
            <p class="hinweis hinweis-info">Die Datei enthält Mitgliederdaten im Klartext. Bewahren Sie sie sicher auf
                und löschen Sie sie, sobald Sie sie nicht mehr brauchen. Sie enthält alle <?= e($anzahl) ?>
                <?= $anzahl === 1 ? 'Eintrag' : 'Einträge' ?> der Auswahl, nicht nur die Seite.</p>
            <form class="formular listen-export-formular" method="post" action="<?= e($pfad) ?>/export">
                <input type="hidden" name="_csrf" value="<?= e((string) $csrf) ?>">
                <?php foreach ($versteckt() as $name => $wert): ?>
                    <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
                <?php endforeach; ?>
                <?php if ($ansicht->sortierung !== null): ?>
                    <input type="hidden" name="sortierung" value="<?= e($ansicht->sortierung) ?>">
                    <?php if ($ansicht->absteigend): ?><input type="hidden" name="richtung" value="ab"><?php endif; ?>
                <?php endif; ?>
                <div class="feld">
                    <label for="export-format">Dateiformat</label>
                    <select id="export-format" name="format">
                        <?php foreach ($formate as $format): ?>
                            <option value="<?= e($format->value) ?>"><?= e($format->bezeichnung()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="formular-aktionen">
                    <button type="submit" class="knopf knopf-haupt">Liste exportieren</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</section>
