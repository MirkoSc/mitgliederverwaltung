<?php

/**
 * Pattern page of the design system (issue #9): every building block of
 * public/css/app.css on one URL. A new page copies its markup from here
 * instead of inventing classes; the checks of a UI pull request - 360 px,
 * PC, light, dark, Barrierefrei-Modus - can be done on this one page.
 *
 * A normal page of the admin area on purpose: same layout, same stylesheet,
 * same CSP as everything else - a separate static demo would drift.
 * Every name, number and address below is made up.
 *
 * @var int|null $gewaehlt the opened example member
 * @var string $reiter its tab
 * @var bool $barrierefreiVorschau
 */

$basis = '/admin/designsystem';
// Every link of the examples keeps the preview mode.
$link = static function (array $parameter = [], string $anker = '') use ($basis, $barrierefreiVorschau): string {
    if ($barrierefreiVorschau) {
        $parameter['barrierefrei'] = '1';
    }

    return $basis . ($parameter === [] ? '' : '?' . http_build_query($parameter)) . ($anker === '' ? '' : '#' . $anker);
};

$mitglieder = [
    1 => [
        'nummer' => '0412', 'name' => 'Musterfrau, Erika', 'ort' => '12345 Musterstadt',
        'anschrift' => 'Wiesenweg 3', 'geburt' => '14.05.1979', 'abteilung' => 'Turnen',
        'beitragsart' => 'Erwachsene', 'status' => ['aktiv', 'etikett-ok'], 'hinweise' => [],
    ],
    2 => [
        'nummer' => '0087', 'name' => 'Mustermann, Max', 'ort' => '12345 Musterstadt',
        'anschrift' => 'Am Sportplatz 1', 'geburt' => '02.11.2012', 'abteilung' => 'Fußball',
        'beitragsart' => 'Familie', 'status' => ['aktiv', 'etikett-ok'], 'hinweise' => [],
    ],
    3 => [
        'nummer' => '1033', 'name' => 'Beispiel, Karl', 'ort' => '12346 Beispieldorf',
        'anschrift' => 'Lindenstraße 27', 'geburt' => '08.03.1962', 'abteilung' => 'Tennis',
        'beitragsart' => 'Rentner', 'status' => ['passiv', ''],
        'hinweise' => ['Beitragsart Rentner, aber erst 64 Jahre alt. Bitte die Beitragsart prüfen.'],
    ],
];
$detail = $gewaehlt !== null ? $mitglieder[$gewaehlt] : null;
?>
<div class="seitenkopf">
    <h1>Designsystem</h1>
    <p>Alle Bausteine der Oberfläche auf einer Seite. Neue Seiten übernehmen das Markup von hier, statt eigene
        Klassen zu erfinden. Hell und dunkel folgen der Einstellung des Geräts.</p>
</div>

<p class="knopfreihe">
    <?php if ($barrierefreiVorschau): ?>
        <a class="knopf" href="<?= e($basis) ?>">Ohne Barrierefrei-Modus ansehen</a>
    <?php else: ?>
        <a class="knopf" href="<?= e($basis . '?barrierefrei=1') ?>">Im Barrierefrei-Modus ansehen</a>
    <?php endif; ?>
    <span class="gedaempft klein">Nur diese Ansicht – Ihre Einstellung im Benutzermenü bleibt, wie sie ist.</span>
</p>

<nav class="sprungleiste" aria-label="Bausteine">
    <ul>
        <li><a href="#muster-grundlagen">Farben und Schrift</a></li>
        <li><a href="#muster-knoepfe">Knöpfe</a></li>
        <li><a href="#muster-meldungen">Meldungen</a></li>
        <li><a href="#muster-formular">Formular</a></li>
        <li><a href="#muster-liste">Liste</a></li>
        <li><a href="#muster-geteilt">Liste neben Mitglied</a></li>
        <li><a href="#muster-weiteres">Weiteres</a></li>
    </ul>
</nav>

<section class="muster" id="muster-grundlagen" aria-labelledby="titel-grundlagen">
    <h2 id="titel-grundlagen">Farben und Schrift</h2>
    <p>Jede Farbe ist ein Token in <code>public/css/app.css</code> und hat einen Wert für hell, dunkel und den
        Barrierefrei-Modus. Ergänzungen unter <code>public/css/teile/</code> verwenden nur diese Tokens.</p>
    <ul class="muster-farben">
        <li class="farbe-grund"><code>--farbe-grund</code> Seitenhintergrund</li>
        <li class="farbe-flaeche"><code>--farbe-flaeche</code> Flächen, Felder</li>
        <li class="farbe-text"><code>--farbe-text</code> Text</li>
        <li class="farbe-gedaempft"><code>--farbe-gedaempft</code> Nebensachen</li>
        <li class="farbe-verein"><code>--farbe-verein</code> Kopfzeile</li>
        <li class="farbe-markierung"><code>--farbe-markierung</code> aktuelle Seite, Fokus im Kopf</li>
        <li class="farbe-haupt"><code>--farbe-haupt</code> Hauptaktion</li>
        <li class="farbe-fehler"><code>--farbe-fehler</code> Fehler, Löschen</li>
    </ul>
    <div class="muster-schrift">
        <p class="klein gedaempft">Schrift: Atkinson Hyperlegible Next – für gute Lesbarkeit gezeichnet, Il1 und 0O
            sind klar unterscheidbar.</p>
        <h1>Überschrift 1 – Mitglieder</h1>
        <h2>Überschrift 2 – Erika Musterfrau</h2>
        <h3>Überschrift 3 – Anschrift</h3>
        <p>Fließtext: Die Mitgliedsnummer <span class="nummer">0412</span> genügt in Listen und Dateinamen. Zeilen
            bleiben unter 70 Zeichen breit, damit sie gut lesbar sind.</p>
        <p class="klein">Kleiner Text für Hilfen und Nebenangaben.</p>
    </div>
</section>

<section class="muster" id="muster-knoepfe" aria-labelledby="titel-knoepfe">
    <h2 id="titel-knoepfe">Knöpfe</h2>
    <p>Ein Knopf sagt, was passiert: „Mitglied speichern“, nicht „Absenden“. Je Bereich höchstens eine
        Hauptaktion.</p>
    <p class="knopfreihe">
        <button type="button" class="knopf knopf-haupt">Mitglied speichern</button>
        <button type="button" class="knopf">Liste exportieren</button>
        <button type="button" class="knopf knopf-gefahr">Mitglied löschen</button>
        <button type="button" class="knopf knopf-still">Filter zurücksetzen</button>
        <button type="button" class="knopf" disabled>Einzug bestätigen</button>
    </p>
    <p class="knopfreihe">
        <a class="knopf knopf-haupt" href="<?= e($link([], 'muster-knoepfe')) ?>">Neues Mitglied</a>
        <span class="gedaempft klein">Ein Link im Aussehen eines Knopfs, wenn er eine Seite öffnet.</span>
    </p>
</section>

<section class="muster" id="muster-meldungen" aria-labelledby="titel-meldungen">
    <h2 id="titel-meldungen">Meldungen, Hinweise und Etiketten</h2>
    <p class="hinweis hinweis-ok">Mitglied gespeichert.</p>
    <p class="hinweis hinweis-info">Die Liste zeigt nur aktive Mitglieder. Ehemalige blenden Sie über den Filter ein.</p>
    <div class="hinweis hinweis-warnung">
        <strong>Hinweise zu diesem Mitglied</strong>
        <ul>
            <li>Beitragsart Rentner, aber erst 64 Jahre alt. Bitte die Beitragsart prüfen.</li>
            <li>Der Schülernachweis ist abgelaufen. Bitte einen neuen Nachweis eintragen.</li>
        </ul>
    </div>
    <p class="hinweis hinweis-fehler">Die IBAN ist ungültig. Bitte prüfen Sie die Ziffern; gespeichert wurde trotzdem.</p>
    <p>Hinweise warnen, sie sperren nie. Nach einer Weiterleitung erscheint dieselbe Darstellung als
        Flash-Meldung oben auf der Seite (<code>App\View\Flash</code>).</p>
    <p class="knopfreihe">
        <span class="etikett etikett-ok">aktiv</span>
        <span class="etikett">passiv</span>
        <span class="etikett etikett-info">Familie</span>
        <span class="etikett etikett-warnung">Nachweis fehlt</span>
        <span class="etikett etikett-fehler">Ehemalig</span>
    </p>
</section>

<section class="muster" id="muster-formular" aria-labelledby="titel-formular">
    <h2 id="titel-formular">Formular</h2>
    <p>Am Handy einspaltig, am PC zweispaltig (<code>formular-zweispaltig</code>), im Barrierefrei-Modus wieder
        einspaltig. Die Leiste mit dem Speichern-Knopf bleibt am unteren Rand sichtbar, solange das Formular
        länger als der Bildschirm ist. Kurze Formulare bleiben einspaltig (nur <code>formular</code>).</p>
    <?php /* Fields without name: the example sends nothing anywhere. */ ?>
    <form class="formular formular-zweispaltig abschnitt" method="get" action="<?= e($basis) ?>#muster-formular">
        <?php if ($barrierefreiVorschau): ?><input type="hidden" name="barrierefrei" value="1"><?php endif; ?>
        <h3>Persönliche Daten</h3>
        <div class="feld">
            <label for="ds-vorname">Vorname</label>
            <input id="ds-vorname" type="text" value="Erika" autocomplete="off">
        </div>
        <div class="feld">
            <label for="ds-nachname">Nachname</label>
            <input id="ds-nachname" type="text" value="Musterfrau" autocomplete="off">
        </div>
        <div class="feld feld-kurz">
            <label for="ds-geburt">Geburtsdatum</label>
            <input id="ds-geburt" type="date" value="1979-05-14">
        </div>
        <div class="feld">
            <label for="ds-abteilung">Abteilung</label>
            <select id="ds-abteilung">
                <option>Fußball</option>
                <option selected>Turnen</option>
                <option>Tennis</option>
            </select>
        </div>
        <div class="feld feld-breit">
            <label for="ds-strasse">Straße und Hausnummer</label>
            <input id="ds-strasse" type="text" value="Wiesenweg 3" autocomplete="off">
        </div>
        <div class="feld">
            <label for="ds-iban">IBAN</label>
            <input id="ds-iban" type="text" value="DE00 1234 5678 9012 3456 78" aria-invalid="true"
                   aria-describedby="ds-iban-fehler" autocomplete="off">
            <p class="feld-fehler" id="ds-iban-fehler">Die Prüfziffer passt nicht. Bitte die IBAN vom Kontoauszug abschreiben.</p>
        </div>
        <div class="feld">
            <label for="ds-telefon">Telefon (optional)</label>
            <input id="ds-telefon" type="tel" aria-describedby="ds-telefon-hilfe" autocomplete="off">
            <p class="feld-hinweis" id="ds-telefon-hilfe">Nur für Rückfragen des Vorstands.</p>
        </div>
        <fieldset class="feldgruppe">
            <legend>Status</legend>
            <label class="feld-haken"><input type="radio" name="ds-status" checked> aktiv</label>
            <label class="feld-haken"><input type="radio" name="ds-status"> passiv</label>
        </fieldset>
        <fieldset class="feldgruppe">
            <legend>Zahlweise</legend>
            <label class="feld-haken"><input type="checkbox" checked> Lastschrift erlaubt (SEPA-Mandat liegt vor)</label>
            <label class="feld-haken"><input type="checkbox"> Zahlt selbst</label>
        </fieldset>
        <div class="feld feld-breit">
            <label for="ds-bemerkung">Bemerkung (optional)</label>
            <textarea id="ds-bemerkung" rows="3"></textarea>
        </div>
        <div class="formular-aktionen">
            <button type="submit" class="knopf knopf-haupt">Mitglied speichern</button>
            <a class="knopf knopf-still" href="<?= e($link([], 'muster-formular')) ?>">Änderungen verwerfen</a>
        </div>
    </form>
</section>

<section class="muster" id="muster-liste" aria-labelledby="titel-liste">
    <h2 id="titel-liste">Suche und Liste</h2>
    <p>Am PC eine Tabelle, am Handy Karten untereinander. Spalten mit <code>nebenspalte</code> entfallen neben
        einem geöffneten Mitglied und im Barrierefrei-Modus. Sortiert wird per Klick auf die Spaltenüberschrift.</p>
    <form class="suche" method="get" action="<?= e($basis) ?>#muster-liste" role="search">
        <?php if ($barrierefreiVorschau): ?><input type="hidden" name="barrierefrei" value="1"><?php endif; ?>
        <div class="feld suche-feld">
            <label for="ds-suche">Mitglied suchen</label>
            <input id="ds-suche" type="search" placeholder="Name, Mitgliedsnummer oder Ort" autocomplete="off">
        </div>
        <div class="filter">
            <div class="feld">
                <label for="ds-filter-abteilung">Abteilung</label>
                <select id="ds-filter-abteilung">
                    <option>Alle Abteilungen</option>
                    <option>Fußball</option>
                    <option>Turnen</option>
                    <option>Tennis</option>
                </select>
            </div>
            <div class="feld">
                <label for="ds-filter-beitrag">Beitragsart</label>
                <select id="ds-filter-beitrag">
                    <option>Alle Beitragsarten</option>
                    <option>Erwachsene</option>
                    <option>Familie</option>
                    <option>Rentner</option>
                </select>
            </div>
            <label class="feld-haken"><input type="checkbox"> Ehemalige anzeigen</label>
            <span class="laedt htmx-indicator">Suche läuft …</span>
        </div>
    </form>

    <div class="tabelle-rahmen">
        <table class="liste">
            <caption>3 Mitglieder, sortiert nach Name</caption>
            <thead>
                <tr>
                    <th scope="col" aria-sort="ascending"><a href="<?= e($link(['sortierung' => 'name-ab'], 'muster-liste')) ?>">Name</a></th>
                    <th scope="col"><a href="<?= e($link(['sortierung' => 'nummer'], 'muster-liste')) ?>">Nr.</a></th>
                    <th scope="col" class="nebenspalte">Ort</th>
                    <th scope="col">Abteilung</th>
                    <th scope="col" class="nebenspalte">Beitragsart</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ([3, 2, 1] as $id): $m = $mitglieder[$id]; ?>
                    <tr>
                        <td class="liste-titel"><a href="<?= e($link(['mitglied' => $id], 'muster-geteilt')) ?>"><?= e($m['name']) ?></a></td>
                        <td data-label="Nr."><span class="nummer"><?= e($m['nummer']) ?></span></td>
                        <td data-label="Ort" class="nebenspalte"><?= e($m['ort']) ?></td>
                        <td data-label="Abteilung"><?= e($m['abteilung']) ?></td>
                        <td data-label="Beitragsart" class="nebenspalte"><?= e($m['beitragsart']) ?></td>
                        <td data-label="Status"><span class="etikett <?= e($m['status'][1]) ?>"><?= e($m['status'][0]) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <nav class="blaettern" aria-label="Seiten der Liste">
        <a class="knopf" href="<?= e($link(['seite' => '1'], 'muster-liste')) ?>">Vorherige Seite</a>
        <p class="blaettern-stand">Seite 2 von 14, je Seite 50 Mitglieder</p>
        <a class="knopf" href="<?= e($link(['seite' => '3'], 'muster-liste')) ?>">Nächste Seite</a>
    </nav>

    <h3>Leere Liste</h3>
    <div class="leer">
        <p>Kein Mitglied passt zu „Mustermann“ in der Abteilung Tennis.</p>
        <p class="knopfreihe">
            <a class="knopf" href="<?= e($link([], 'muster-liste')) ?>">Alle Filter zurücksetzen</a>
            <a class="knopf knopf-haupt" href="<?= e($link([], 'muster-liste')) ?>">Neues Mitglied</a>
        </p>
    </div>
</section>

<section class="muster" id="muster-geteilt" aria-labelledby="titel-geteilt">
    <h2 id="titel-geteilt">Liste neben Mitglied</h2>
    <p>Ein Klick auf ein Mitglied öffnet es auf derselben Seite: am PC rechts neben der Liste (ohne geöffnetes
        Mitglied hat die Liste die ganze Breite), im Barrierefrei-Modus darunter, am Handy als eigene Ansicht mit
        „Zurück zur Liste“. Am PC schließen „Schließen“ oder ein zweiter Klick auf das Mitglied. Der Server setzt
        dafür <code>geteilt-mit-detail</code> – ohne Skript. Echte Seiten betten das Mitglied als Baustein ein
        (<code>MitgliedAnsicht</code>); seine Überschrift bekommt nach dem Öffnen den Fokus.</p>

    <div class="geteilt<?= $detail !== null ? ' geteilt-mit-detail' : '' ?>">
        <div class="geteilt-liste">
            <table class="liste">
                <caption class="nur-lesehilfe">Mitglieder</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Nr.</th>
                        <th scope="col" class="nebenspalte">Abteilung</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ([3, 2, 1] as $id): $m = $mitglieder[$id]; ?>
                        <tr>
                            <td class="liste-titel"><a href="<?= e($link($id === $gewaehlt ? [] : ['mitglied' => $id], 'muster-geteilt')) ?>"<?= $id === $gewaehlt ? ' aria-current="true"' : '' ?>><?= e($m['name']) ?></a></td>
                            <td data-label="Nr."><span class="nummer"><?= e($m['nummer']) ?></span></td>
                            <td data-label="Abteilung" class="nebenspalte"><?= e($m['abteilung']) ?></td>
                            <td data-label="Status"><span class="etikett <?= e($m['status'][1]) ?>"><?= e($m['status'][0]) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="geteilt-detail">
            <?php if ($detail !== null): ?>
                <a class="zurueck" href="<?= e($link([], 'muster-geteilt')) ?>">Zurück zur Liste</a>
                <div class="detail-kopf">
                    <h3 tabindex="-1"><?= e($detail['name']) ?></h3>
                    <span class="nummer">Nr. <?= e($detail['nummer']) ?></span>
                    <span class="etikett <?= e($detail['status'][1]) ?>"><?= e($detail['status'][0]) ?></span>
                    <a class="knopf knopf-still detail-schliessen" href="<?= e($link([], 'muster-geteilt')) ?>">Schließen</a>
                </div>
                <?php if ($detail['hinweise'] !== []): ?>
                    <div class="hinweis hinweis-warnung">
                        <strong>Hinweise zu diesem Mitglied</strong>
                        <ul>
                            <?php foreach ($detail['hinweise'] as $hinweis): ?>
                                <li><?= e($hinweis) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <nav class="reiter" aria-label="Bereiche des Mitglieds">
                    <ul>
                        <?php foreach (['daten' => 'Daten', 'beitraege' => 'Beiträge', 'verlauf' => 'Verlauf'] as $schluessel => $titel): ?>
                            <li><a href="<?= e($link(['mitglied' => $gewaehlt, 'reiter' => $schluessel], 'muster-geteilt')) ?>"<?= $schluessel === $reiter ? ' aria-current="page"' : '' ?>><?= e($titel) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
                <?php if ($reiter === 'daten'): ?>
                    <dl class="angaben">
                        <dt>Anschrift</dt>
                        <dd><?= e($detail['anschrift']) ?>, <?= e($detail['ort']) ?></dd>
                        <dt>Geburtsdatum</dt>
                        <dd><?= e($detail['geburt']) ?></dd>
                        <dt>Abteilung</dt>
                        <dd><?= e($detail['abteilung']) ?></dd>
                        <dt>Beitragsart</dt>
                        <dd><?= e($detail['beitragsart']) ?></dd>
                    </dl>
                    <p class="knopfreihe">
                        <a class="knopf knopf-haupt" href="<?= e($link(['mitglied' => $gewaehlt], 'muster-formular')) ?>">Daten bearbeiten</a>
                    </p>
                <?php elseif ($reiter === 'beitraege'): ?>
                    <table class="liste">
                        <caption class="nur-lesehilfe">Jahresbeiträge</caption>
                        <thead>
                            <tr>
                                <th scope="col">Jahr</th>
                                <th scope="col" class="zahl">Betrag</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="liste-titel">2026</td>
                                <td data-label="Betrag" class="zahl"><?= e(geld(9600)) ?></td>
                                <td data-label="Status"><span class="etikett etikett-warnung">offen</span></td>
                            </tr>
                            <tr>
                                <td class="liste-titel">2025</td>
                                <td data-label="Betrag" class="zahl"><?= e(geld(9600)) ?></td>
                                <td data-label="Status"><span class="etikett etikett-ok">bezahlt</span></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <dl class="angaben">
                        <dt>03.02.2026, 18:40</dt>
                        <dd>Anschrift geändert (Vorstand)</dd>
                        <dt>15.01.2024, 09:12</dt>
                        <dd>Mitglied angelegt (Import)</dd>
                    </dl>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="muster" id="muster-weiteres" aria-labelledby="titel-weiteres">
    <h2 id="titel-weiteres">Weiteres</h2>

    <h3>Abschnitt</h3>
    <div class="abschnitt">
        <h4>Vereinsdaten</h4>
        <p>Ein Abschnitt fasst zusammengehörige Einstellungen zusammen – auf der Seite „Einstellungen“ einer je
            Thema.</p>
    </div>

    <h3>Auswahl</h3>
    <ul class="auswahl">
        <li>
            <a href="<?= e($link([], 'muster-weiteres')) ?>">Geburtstage</a>
            <p>Wer in dieser Woche, diesem Monat oder Jahr Geburtstag hat.</p>
        </li>
        <li>
            <a href="<?= e($link([], 'muster-weiteres')) ?>">Ehrungen</a>
            <p>Wer in diesem Jahr für 25, 40 oder 50 Jahre geehrt wird.</p>
        </li>
    </ul>

    <h3>Ladeanzeige</h3>
    <p>htmx blendet Elemente mit <code>htmx-indicator</code> ein, solange eine Anfrage läuft. Zur Ansicht hier
        dauerhaft sichtbar:</p>
    <p><span class="laedt">Wird geladen …</span></p>

    <h3>Kopfzeile</h3>
    <p>Links das Vereinslogo mit dem Namen der Anwendung, rechts das Benutzermenü, darunter die Hauptnavigation.
        Bis der Verein sein Logo hochlädt, steht dort ein neutrales Wappen (<code>App\View\View::setLogo()</code>).</p>
</section>
