<?php

/**
 * The page "Karte" (issue #91): the stand of the geocoding, the filter, the
 * map, and below it the same addresses as text - the table rows carry the
 * coordinates, public/js/karte.js builds the markers from them. Without
 * script, and in the Barrierefrei-Modus, the text lists are the page.
 *
 * @var \App\Service\Karte\KartenAnsicht $ansicht
 * @var \App\Service\Karte\ErmittlungsStand $stand
 * @var list<\App\Domain\Abteilung> $abteilungen
 * @var int|null $abteilungId
 * @var \App\Domain\MitgliederStatus $status
 * @var bool $gefiltert
 * @var bool $darfErmitteln
 * @var string $kachelUrl
 * @var string $mitgliedPfad
 * @var string|null $csrf
 */

use App\App\KarteController;
use App\Domain\MitgliederStatus;
use App\Service\Karte\KachelProxy;

$mitgliederListe = static function (\App\Service\Karte\KartenOrt $ort) use ($mitgliedPfad): string {
    $html = '<ul class="karte-mitglieder">';
    foreach ($ort->mitglieder as $m) {
        $html .= '<li><a href="' . e($mitgliedPfad . '?mitglied=' . $m->id) . '">' . e($m->name()) . '</a> '
            . '<span class="nummer">' . e((string) $m->mitgliedsnummer) . '</span>'
            . ($m->aktiv ? '' : ' <span class="etikett">passiv</span>') . '</li>';
    }

    return $html . '</ul>';
};
$anzahlText = static fn(int $n, string $eins, string $mehr): string => $n . ' ' . ($n === 1 ? $eins : $mehr);
?>
<div class="seitenkopf">
    <h1>Karte</h1>
    <p id="karte-stand" aria-live="polite">
        <?= e($stand->text()) ?>.
        <?php if ($stand->pauseBis !== null): ?>
            Der Kartendienst hat zuletzt nicht geantwortet; der nächste Versuch folgt ab <?= e($stand->pauseBis->format('H:i')) ?> Uhr.
        <?php elseif ($stand->offen() > 0): ?>
            <?= e($anzahlText($stand->offen(), 'Anschrift wird', 'Anschriften werden')) ?> noch nach und nach ermittelt.
        <?php endif; ?>
    </p>
    <?php if ($darfErmitteln && $stand->offen() > 0): ?>
        <form id="karte-ermitteln" class="karte-ermitteln" method="post" action="<?= e(KarteController::PFAD_ERMITTELN) ?>" data-csrf="<?= e((string) $csrf) ?>">
            <input type="hidden" name="_csrf" value="<?= e((string) $csrf) ?>">
            <button type="submit" class="knopf">Jetzt Koordinaten ermitteln</button>
            <span class="laedt" id="karte-ermitteln-status" hidden></span>
        </form>
    <?php endif; ?>
</div>

<form class="suche karte-filter" method="get" action="<?= e(\App\View\Navigation::KARTE) ?>">
    <div class="filter">
        <div class="feld">
            <label for="karte-abteilung">Abteilung</label>
            <select id="karte-abteilung" name="abteilung">
                <option value="">Alle Abteilungen</option>
                <?php foreach ($abteilungen as $abteilung): ?>
                    <option value="<?= e((string) $abteilung->id) ?>"<?= $abteilung->id === $abteilungId ? ' selected' : '' ?>><?= e($abteilung->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="feld">
            <label for="karte-status">Aktiv oder passiv</label>
            <select id="karte-status" name="status">
                <?php foreach (MitgliederStatus::cases() as $fall): ?>
                    <option value="<?= e($fall->value) ?>"<?= $fall === $status ? ' selected' : '' ?>><?= e($fall->bezeichnung()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="knopf">Filter anwenden</button>
        <?php if ($gefiltert): ?>
            <a class="knopf knopf-still" href="<?= e(\App\View\Navigation::KARTE) ?>">Filter zurücksetzen</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($ansicht->gefunden !== []): ?>
    <div id="karte" class="karte-flaeche"
         data-kacheln="<?= e($kachelUrl) ?>"
         data-zoom-min="<?= KachelProxy::ZOOM_MIN ?>"
         data-zoom-max="<?= KachelProxy::ZOOM_MAX ?>"
         data-orte="#karte-orte"
         role="region"
         aria-label="Karte mit den Anschriften der Tabelle „Anschriften auf der Karte“"></div>
<?php endif; ?>

<section class="karte-abschnitt" aria-labelledby="karte-titel-orte">
    <h2 id="karte-titel-orte">Anschriften auf der Karte (<?= count($ansicht->gefunden) ?>)</h2>
    <?php if ($ansicht->gefunden === []): ?>
        <div class="leer">
            <p><?= $gefiltert
                ? 'Für diesen Filter ist noch keine Anschrift auf der Karte.'
                : 'Noch ist keine Anschrift auf der Karte. Die Koordinaten werden nach und nach im Hintergrund ermittelt.' ?></p>
        </div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="liste karte-liste" id="karte-orte">
                <caption><?= e($anzahlText($ansicht->anzahlMitglieder(), 'Mitglied', 'Mitglieder')) ?><?= $gefiltert ? ' im Filter' : '' ?>, sortiert nach Ort und Straße</caption>
                <thead>
                    <tr>
                        <th scope="col">Anschrift</th>
                        <th scope="col" class="zahl">Anzahl</th>
                        <th scope="col">Mitglieder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ansicht->gefunden as $ort): ?>
                        <tr data-lat="<?= e(number_format((float) $ort->lat, 6, '.', '')) ?>" data-lon="<?= e(number_format((float) $ort->lon, 6, '.', '')) ?>">
                            <td class="liste-titel" data-karte-anschrift><?= e($ort->anschrift->text()) ?></td>
                            <td class="zahl" data-label="Anzahl" data-karte-anzahl><?= count($ort->mitglieder) ?></td>
                            <td data-label="Mitglieder" data-karte-mitglieder><?= $mitgliederListe($ort) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php foreach ([
    ['nicht-gefunden', 'Nicht gefundene Anschriften', $ansicht->nichtGefunden, 'Der Kartendienst kennt diese Anschriften nicht. Eine Korrektur am Mitglied (z. B. Straße oder PLZ) lässt sie neu suchen.'],
    ['offen', 'Noch nicht ermittelt', $ansicht->offen, 'Diese Anschriften kommen in den nächsten Durchläufen dran.'],
] as [$schluessel, $titel, $orte, $erklaerung]): ?>
    <?php if ($orte !== []): ?>
        <section class="karte-abschnitt" aria-labelledby="karte-titel-<?= e($schluessel) ?>">
            <h2 id="karte-titel-<?= e($schluessel) ?>"><?= e($titel) ?> (<?= count($orte) ?>)</h2>
            <p class="feld-hinweis"><?= e($erklaerung) ?></p>
            <div class="tabelle-rahmen">
                <table class="liste">
                    <thead>
                        <tr>
                            <th scope="col">Anschrift</th>
                            <th scope="col">Mitglieder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orte as $ort): ?>
                            <tr>
                                <td class="liste-titel"><?= e($ort->anschrift->text()) ?></td>
                                <td data-label="Mitglieder"><?= $mitgliederListe($ort) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($ansicht->ohneAnschrift !== []): ?>
    <section class="karte-abschnitt" aria-labelledby="karte-titel-ohne">
        <h2 id="karte-titel-ohne">Ohne Anschrift (<?= count($ansicht->ohneAnschrift) ?>)</h2>
        <p class="feld-hinweis">Bei diesen Mitgliedern fehlen PLZ und Ort.</p>
        <ul class="karte-mitglieder">
            <?php foreach ($ansicht->ohneAnschrift as $m): ?>
                <li><a href="<?= e($mitgliedPfad . '?mitglied=' . $m->id) ?>"><?= e($m->name()) ?></a> <span class="nummer"><?= e((string) $m->mitgliedsnummer) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
