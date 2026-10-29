<?php

/**
 * The reconciliation of the settings section Import (docs/spec/10-import.md
 * section 4), included by import.php: the merge view - Neu / Fehlt in der
 * Datei / Geändert, one tick per row - or the progress of a started chain.
 * The ticks are collected and the chain is driven by public/js/abgleich.js.
 *
 * @var string $zustand abgleich | abgleich-laeuft
 * @var \App\Service\Import\AbgleichVorschau|null $abgleichVorschau
 * @var array<string, mixed> $abgleichStand
 * @var \DateTimeImmutable|null $heute
 * @var string $csrf
 * @var \Closure(int): string $zahl
 * @var int $zeigen
 */

$verlauf = static function (): void { ?>
    <div id="abgleich-verlauf" class="import-verlauf" hidden>
        <progress id="abgleich-fortschritt" max="100" aria-labelledby="abgleich-status"></progress>
        <p id="abgleich-status" aria-live="polite"></p>
        <p id="abgleich-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
    </div>
<?php };

$verwerfen = static function (string $csrf): void { ?>
    <form method="post" action="/admin/import/abgleich/verwerfen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-still">Datei verwerfen</button>
        </p>
    </form>
<?php };

/** "Alle an" / "Alle aus" for the ticks of one section. */
$alle = static function (string $gruppe, string $wofuer): void { ?>
    <p class="knopfreihe abgleich-alle">
        <button type="button" class="knopf knopf-still" data-abgleich-alle="<?= e($gruppe) ?>" data-an="1">Alle an<span class="nur-lesehilfe"> (<?= e($wofuer) ?>)</span></button>
        <button type="button" class="knopf knopf-still" data-abgleich-alle="<?= e($gruppe) ?>" data-an="0">Alle aus<span class="nur-lesehilfe"> (<?= e($wofuer) ?>)</span></button>
    </p>
<?php };
?>

<?php if ($zustand === 'abgleich-laeuft'): ?>
    <p class="hinweis hinweis-warnung" role="status">
        Ein Abgleich ist angefangen: <?= e($zahl((int) ($abgleichStand['offset'] ?? 0))) ?> von
        <?= e($zahl((int) ($abgleichStand['gesamt'] ?? 0))) ?> angehakten Zeilen sind übernommen. Bitte den
        Abgleich fortsetzen – bereits Übernommenes wird dabei nicht doppelt ausgeführt.
    </p>
    <noscript>
        <p class="hinweis hinweis-fehler">Für den Abgleich wird JavaScript benötigt.</p>
    </noscript>
    <form method="post" action="/admin/import/abgleich/verwerfen" id="abgleich-weiter" data-csrf="<?= e($csrf) ?>" data-schritt="/admin/import/abgleich/schritt">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="knopfreihe">
            <button type="button" class="knopf knopf-haupt" id="abgleich-fortsetzen">Abgleich fortsetzen</button>
            <button type="submit" class="knopf knopf-still">Abgleich abbrechen</button>
        </p>
    </form>
    <?php $verlauf(); ?>

<?php elseif ($abgleichVorschau !== null && !$abgleichVorschau->istFehlerfrei()): ?>
    <?php $datei = $abgleichVorschau->datei; ?>
    <p class="hinweis hinweis-fehler" role="alert">
        Die Datei enthält Fehler. Abgeglichen wird nur eine Datei ohne Fehler: bitte die Fehler beheben
        (im Umwandlungsskript oder in den Einstellungen) und die Datei neu hochladen.
    </p>
    <div class="tabelle-rahmen">
        <table class="liste">
            <caption class="nur-lesehilfe">Fehler in der Datei</caption>
            <thead>
                <tr>
                    <th scope="col" class="zahl">Zeile</th>
                    <th scope="col">Mitglied Nr.</th>
                    <th scope="col">Fehler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($datei->fehler, 0, $zeigen) as $fehler): ?>
                    <tr>
                        <td data-label="Zeile" class="zahl"><?= $fehler->zeile === 0 ? '–' : e($fehler->zeile) ?></td>
                        <td data-label="Mitglied Nr."><?= $fehler->nummer === null ? '–' : e($fehler->nummer) ?></td>
                        <td class="liste-titel"><?= e($fehler->text) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($datei->fehler) > $zeigen): ?>
        <p class="gedaempft">… und <?= e($zahl(count($datei->fehler) - $zeigen)) ?> weitere Fehler.</p>
    <?php endif; ?>
    <?php $verwerfen($csrf); ?>

<?php elseif ($abgleichVorschau !== null && $abgleichVorschau->vergleich !== null): ?>
    <?php
    $v = $abgleichVorschau->vergleich;
    $felder = $abgleichVorschau->felder;
    $unbekannt = $abgleichVorschau->datei->unbekannteSpalten;
    ?>
    <?php if ($unbekannt !== []): ?>
        <p class="hinweis hinweis-info">Diese Spalten kennt der Import nicht und ignoriert sie: <?= e(implode(', ', $unbekannt)) ?>.</p>
    <?php endif; ?>

    <?php if ($v->istLeer()): ?>
        <p class="hinweis hinweis-ok" role="status">
            Datei und Bestand stimmen überein (<?= e($zahl($v->unveraendert)) ?> Mitglieder ohne Änderung).
            Es gibt nichts zu übernehmen.
        </p>
        <?php $verwerfen($csrf); ?>
    <?php else: ?>
        <p>
            Übernommen wird nur, was angehakt ist. Neue Mitglieder sind vorausgewählt, alles andere nicht.
            Jede Übernahme wird beim Mitglied als Version „Abgleich“ gespeichert und lässt sich dort zurückholen.
        </p>
        <dl class="angaben">
            <dt>Neu</dt>
            <dd><?= e($zahl(count($v->neu))) ?></dd>
            <dt>Fehlt in der Datei</dt>
            <dd><?= e($zahl(count($v->fehlt))) ?></dd>
            <dt>Geändert</dt>
            <dd><?= e($zahl(count($v->geaendert))) ?></dd>
            <dt>Unverändert</dt>
            <dd><?= e($zahl($v->unveraendert)) ?></dd>
        </dl>

        <form method="post" action="/admin/import/abgleich/starten" id="abgleich-starten" class="abgleich" data-csrf="<?= e($csrf) ?>" data-schritt="/admin/import/abgleich/schritt">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <noscript>
                <p class="hinweis hinweis-fehler">Für den Abgleich wird JavaScript benötigt.</p>
            </noscript>

            <h3 id="abgleich-neu">Neu</h3>
            <p>In der Datei, aber nicht in der Anwendung. Angehakte Mitglieder werden angelegt.</p>
            <?php if ($v->neu === []): ?>
                <p class="leer">Die Datei enthält keine neuen Mitglieder.</p>
            <?php else: ?>
                <?php $alle('neu', 'Neu'); ?>
                <div class="tabelle-rahmen">
                    <table class="liste">
                        <caption class="nur-lesehilfe">Neue Mitglieder</caption>
                        <thead>
                            <tr>
                                <th scope="col">Anlegen</th>
                                <th scope="col">Nr.</th>
                                <th scope="col">Name</th>
                                <th scope="col">Geburtsdatum</th>
                                <th scope="col" class="nebenspalte">Familie</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($v->neu as $eintrag): ?>
                                <?php $m = $eintrag->mitglied; ?>
                                <tr>
                                    <td>
                                        <label class="feld-haken">
                                            <input type="checkbox" name="neu" value="<?= e($m->mitgliedsnummer) ?>" checked>
                                            Anlegen<span class="nur-lesehilfe">: Nr. <?= e($m->mitgliedsnummer) ?></span>
                                        </label>
                                    </td>
                                    <td data-label="Nr."><span class="nummer"><?= e($m->mitgliedsnummer) ?></span></td>
                                    <td class="liste-titel"><?= e($m->anzeigeName()) ?></td>
                                    <td data-label="Geburtsdatum"><?= e(datum($m->geburtsdatum)) ?></td>
                                    <td data-label="Familie" class="nebenspalte"><?= e($felder->familieDerDatei($eintrag->familie)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3 id="abgleich-fehlt">Fehlt in der Datei</h3>
            <p>
                Importiert, aber in der neuen Datei nicht mehr enthalten. Angehakte Mitglieder treten aus –
                oder werden ganz gelöscht, wenn „ganz löschen“ angehakt ist. Von Hand angelegte und bereits
                ausgetretene Mitglieder stehen hier nie.
            </p>
            <?php if ($v->fehlt === []): ?>
                <p class="leer">Kein importiertes Mitglied fehlt in der Datei.</p>
            <?php else: ?>
                <div class="feld feld-kurz">
                    <label for="abgleich-austrittsdatum">Austrittsdatum</label>
                    <input id="abgleich-austrittsdatum" name="austrittsdatum" type="date" value="<?= e(($heute ?? new \DateTimeImmutable('today'))->format('Y-m-d')) ?>" required>
                </div>
                <?php $alle('austritt', 'Fehlt in der Datei'); ?>
                <div class="tabelle-rahmen">
                    <table class="liste">
                        <caption class="nur-lesehilfe">Mitglieder, die in der Datei fehlen</caption>
                        <thead>
                            <tr>
                                <th scope="col">Austreten</th>
                                <th scope="col">Nr.</th>
                                <th scope="col">Name</th>
                                <th scope="col" class="nebenspalte">Eintritt</th>
                                <th scope="col">Ganz löschen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($v->fehlt as $zeile): ?>
                                <?php $m = $zeile->mitglied; ?>
                                <tr>
                                    <td>
                                        <label class="feld-haken">
                                            <input type="checkbox" name="austritt" value="<?= e($m->mitgliedsnummer) ?>">
                                            Austreten<span class="nur-lesehilfe">: Nr. <?= e($m->mitgliedsnummer) ?></span>
                                        </label>
                                    </td>
                                    <td data-label="Nr."><span class="nummer"><?= e($m->mitgliedsnummer) ?></span></td>
                                    <td class="liste-titel"><?= e($m->anzeigeName()) ?></td>
                                    <td data-label="Eintritt" class="nebenspalte"><?= $m->eintrittsdatum === null ? 'unbekannt' : e(datum($m->eintrittsdatum)) ?></td>
                                    <td data-label="Ganz löschen">
                                        <?php if ($zeile->kannLoeschen): ?>
                                            <label class="feld-haken">
                                                <input type="checkbox" name="loeschen" value="<?= e($m->mitgliedsnummer) ?>">
                                                ganz löschen<span class="nur-lesehilfe">: Nr. <?= e($m->mitgliedsnummer) ?></span>
                                            </label>
                                        <?php else: ?>
                                            <span class="gedaempft">nicht möglich, hat Jahresbeiträge</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3 id="abgleich-geaendert">Geändert</h3>
            <p>
                In beiden enthalten, mit abweichenden Werten: links der Wert in der Anwendung, rechts der in der
                Datei. Ein angehaktes Mitglied bekommt alle Werte aus der Datei – einzelne Felder danach am
                Mitglied von Hand ändern.
            </p>
            <?php if ($v->geaendert === []): ?>
                <p class="leer">Bei keinem Mitglied weichen die Werte ab.</p>
            <?php else: ?>
                <?php $alle('aendern', 'Geändert'); ?>
                <div class="tabelle-rahmen">
                    <table class="liste">
                        <caption class="nur-lesehilfe">Mitglieder mit abweichenden Werten</caption>
                        <thead>
                            <tr>
                                <th scope="col">Übernehmen</th>
                                <th scope="col">Nr.</th>
                                <th scope="col">Name</th>
                                <th scope="col">Unterschiede</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($v->geaendert as $aenderung): ?>
                                <?php $m = $aenderung->vorher; ?>
                                <tr>
                                    <td>
                                        <label class="feld-haken">
                                            <input type="checkbox" name="aendern" value="<?= e($m->mitgliedsnummer) ?>">
                                            Übernehmen<span class="nur-lesehilfe">: Nr. <?= e($m->mitgliedsnummer) ?></span>
                                        </label>
                                    </td>
                                    <td data-label="Nr."><span class="nummer"><?= e($m->mitgliedsnummer) ?></span></td>
                                    <td class="liste-titel"><?= e($m->anzeigeName()) ?></td>
                                    <td data-label="Unterschiede">
                                        <ul class="abgleich-unterschiede">
                                            <?php foreach ($aenderung->felder as $feld => [$alt, $neu]): ?>
                                                <?php $altText = $felder->wert($feld, $alt); $neuText = $felder->wert($feld, $neu); ?>
                                                <li>
                                                    <span class="abgleich-feld"><?= e($felder->name($feld)) ?>:</span>
                                                    <del><?= $altText === '' ? '<span class="abgleich-leer">leer</span>' : e($altText) ?></del>
                                                    <span aria-hidden="true">→</span><span class="nur-lesehilfe">wird zu</span>
                                                    <ins><?= $neuText === '' ? '<span class="abgleich-leer">leer</span>' : e($neuText) ?></ins>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="knopfreihe abgleich-uebernehmen">
                <button type="submit" class="knopf knopf-haupt" id="abgleich-start">Angehakte Zeilen übernehmen</button>
            </p>
        </form>
        <?php $verwerfen($csrf); ?>
        <?php $verlauf(); ?>
    <?php endif; ?>
<?php endif; ?>
