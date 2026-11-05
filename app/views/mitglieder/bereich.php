<?php

/**
 * List and opened member side by side (design system "Liste neben
 * Mitglied"): the server marks an opened member with geteilt-mit-detail,
 * the stylesheet does the rest - no script.
 *
 * The search form posts to the page itself (htmx swaps only the result
 * list; without a script it is an ordinary form). Its address carries what
 * is not a field - sort order, opened member - and its fields the rest, so
 * the search text never appears in a URL.
 *
 * @var \App\Domain\MitgliederFilter $filter
 * @var \App\Domain\MitgliederSeite $ergebnis
 * @var list<\App\Domain\Abteilung> $abteilungen
 * @var list<\App\Domain\Beitragsart> $beitragsarten
 * @var list<\App\Domain\Funktion> $funktionen
 * @var \App\Domain\Mitglied|null $mitglied
 * @var \App\App\Mitglied\GeoeffnetesMitglied $ansicht the opened member (building block, 06 section 5)
 * @var \Closure $url
 * @var string $formUrl
 * @var string|null $csrf
 */
?>
<div class="geteilt<?= $ansicht->klasse() ?>" id="mitglieder-bereich">
    <div class="geteilt-liste">
        <?php if ($ansicht->unbekannt): ?>
            <p class="hinweis hinweis-warnung">Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es gelöscht.</p>
        <?php endif; ?>

        <?php /* Typing waits for a pause (delay), a change of a filter does not.
                 hx-sync drops the answer of a request a newer one has replaced.
                 "Je Seite" sits in the pager inside the result (ergebnis.php), tied
                 to this form by its form attribute; the result is swapped but not
                 replaced, so a change event from it is listened for there. */ ?>
        <form class="suche" id="mitglieder-form" method="post" action="<?= e($formUrl) ?>" role="search"
              hx-post="<?= e($formUrl) ?>" hx-target="#mitglieder-ergebnis" hx-swap="innerHTML"
              hx-indicator="#suche-laedt" hx-sync="this:replace"
              hx-trigger="input changed delay:300ms from:#suche-text, change from:#filter-abteilung, change from:#filter-status, change from:#filter-beitragsart, change from:#filter-ehemalige, input delay:300ms from:#suche-erweitert, change from:#mitglieder-ergebnis, submit">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <div class="feld suche-feld">
                <label for="suche-text">Mitglied suchen</label>
                <input id="suche-text" name="text" type="search" value="<?= e($filter->text) ?>"
                       placeholder="z. B. Müller 1980" autocomplete="off" aria-describedby="suche-hilfe">
                <p class="feld-hinweis" id="suche-hilfe">Sucht in Name, Mitgliedsnummer, Anschrift, E-Mail, Telefon, Kontoinhaber, IBAN, Geburtsdatum und Bemerkung. Jedes Wort muss passen, die Reihenfolge ist egal, zum Beispiel „Hauptstr 12 Musterort“ oder „Anna 01.02.1980“.</p>
            </div>
            <div class="filter">
                <div class="feld">
                    <label for="filter-abteilung">Abteilung</label>
                    <select id="filter-abteilung" name="abteilung">
                        <option value="">Alle Abteilungen</option>
                        <?php foreach ($abteilungen as $abteilung): ?>
                            <option value="<?= e($abteilung->id) ?>"<?= $abteilung->id === $filter->abteilungId ? ' selected' : '' ?>><?= e($abteilung->name . ($abteilung->aktiv ? '' : ' (nicht mehr aktiv)')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="feld">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status">
                        <?php foreach (\App\Domain\MitgliederStatus::cases() as $status): ?>
                            <option value="<?= e($status->value) ?>"<?= $status === $filter->status ? ' selected' : '' ?>><?= e($status->bezeichnung()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="feld">
                    <label for="filter-beitragsart">Beitragsart</label>
                    <select id="filter-beitragsart" name="beitragsart">
                        <option value="">Alle Beitragsarten</option>
                        <?php foreach ($beitragsarten as $beitragsart): ?>
                            <option value="<?= e($beitragsart->id) ?>"<?= $beitragsart->id === $filter->beitragsartId ? ' selected' : '' ?>><?= e($beitragsart->name . ($beitragsart->aktiv ? '' : ' (nicht mehr aktiv)')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php /* An unticked box sends nothing; the hidden 0 keeps "unticked"
                         from falling back to a stale value in the address. */ ?>
                <input type="hidden" name="ehemalige" value="0">
                <label class="feld-haken"><input id="filter-ehemalige" type="checkbox" name="ehemalige" value="1"<?= $filter->mitEhemaligen ? ' checked' : '' ?>> Ehemalige anzeigen</label>
                <button type="submit" class="knopf">Suchen</button>
                <a class="knopf knopf-still" href="<?= e(\App\App\MitgliederController::PFAD . ($mitglied !== null ? '?mitglied=' . $mitglied->id : '')) ?>">Filter zurücksetzen</a>
                <span class="laedt htmx-indicator" id="suche-laedt">Suche läuft …</span>
            </div>
            <?php
            $e = $filter->erweitert;
            // A text field of the extended search; the value goes back into the form, never into an address.
            $textfeld = static function (string $name, string $titel, string $wert, string $typ = 'text') {
                return '<div class="feld"><label for="erweitert-' . e($name) . '">' . e($titel) . '</label>'
                    . '<input id="erweitert-' . e($name) . '" name="' . e($name) . '" type="' . $typ . '" value="' . e($wert) . '" autocomplete="off"></div>';
            };
            // Two fields "from - to" in one group for the screen reader.
            $bereich = static function (string $name, string $titel, string $art, ?string $von, ?string $bis, string $typ = 'date') {
                $attr = $typ === 'number' ? ' min="0" max="150" inputmode="numeric"' : '';
                $feld = static fn(string $suffix, string $label, ?string $wert): string => '<div class="feld"><label for="erweitert-' . e($name) . '-' . $suffix . '">' . e($label) . '</label>'
                    . '<input id="erweitert-' . e($name) . '-' . $suffix . '" name="' . e($name) . '_' . $suffix . '" type="' . $typ . '"' . $attr . ' value="' . e($wert ?? '') . '" autocomplete="off"></div>';

                return '<fieldset class="suche-bereich"><legend>' . e($titel) . '</legend><div class="suche-bereich-paar">'
                    . $feld('von', $art === 'alter' ? 'von (Jahre)' : 'von', $von) . $feld('bis', $art === 'alter' ? 'bis (Jahre)' : 'bis', $bis)
                    . '</div></fieldset>';
            };
            $auswahl = static function (string $name, string $titel, string $gewaehlt, array $optionen, string $alle) {
                $html = '<div class="feld"><label for="erweitert-' . e($name) . '">' . e($titel) . '</label><select id="erweitert-' . e($name) . '" name="' . e($name) . '">'
                    . '<option value="">' . e($alle) . '</option>';
                foreach ($optionen as $wert => $text) {
                    $html .= '<option value="' . e((string) $wert) . '"' . ((string) $wert === $gewaehlt ? ' selected' : '') . '>' . e($text) . '</option>';
                }

                return $html . '</select></div>';
            };
            ?>
            <?php /* Open when a field is filled, so a search in progress is never hidden. The
                     values travel in the body only (hx-post/POST), see MitgliederFilter::parameter(). */ ?>
            <details class="suche-erweitert" id="suche-erweitert"<?= $e->istLeer() ? '' : ' open' ?>>
                <summary>Erweiterte Suche</summary>
                <div class="suche-erweitert-felder">
                    <?= $textfeld('nachname', 'Nachname', $e->nachname) ?>
                    <?= $textfeld('vorname', 'Vorname', $e->vorname) ?>
                    <?= $textfeld('strasse', 'Straße', $e->strasse) ?>
                    <?= $textfeld('plz', 'PLZ', $e->plz) ?>
                    <?= $textfeld('ort', 'Ort', $e->ort) ?>
                    <?= $textfeld('email', 'E-Mail', $e->email) ?>
                    <?= $textfeld('telefon', 'Telefon oder Handy', $e->telefon) ?>
                    <?= $textfeld('iban', 'IBAN', $e->iban) ?>
                    <?= $textfeld('bemerkung', 'Bemerkung', $e->bemerkung) ?>
                    <?= $textfeld('mitgliedsnummer', 'Mitgliedsnummer (genau)', (string) ($e->mitgliedsnummer ?? ''), 'number') ?>
                    <?= $auswahl('geschlecht', 'Geschlecht', $e->geschlecht, [
                        ...array_column(
                            array_map(static fn($g): array => [$g->value, $g->bezeichnung()], \App\Domain\Geschlecht::cases()),
                            1,
                            0,
                        ),
                        \App\Domain\ErweiterteSuche::OHNE_ANGABE => 'ohne Angabe',
                    ], 'Alle') ?>
                    <?= $auswahl('funktion', 'Funktion', (string) ($e->funktionId ?? ''), array_combine(
                        array_map(static fn($f): int => (int) $f->id, $funktionen),
                        array_map(static fn($f): string => $f->name . ($f->aktiv ? '' : ' (nicht mehr aktiv)'), $funktionen),
                    ), 'Alle Funktionen') ?>
                    <?= $auswahl('zahlart', 'Zahlart', $e->zahlart?->value ?? '', array_column(
                        array_map(static fn($z): array => [$z->value, $z->bezeichnung()], \App\Domain\Zahlart::cases()),
                        1,
                        0,
                    ), 'Alle Zahlarten') ?>
                    <?= $auswahl('familie', 'Familie', $e->familie, [
                        \App\Domain\ErweiterteSuche::MIT_FAMILIE => 'In einer Familie',
                        \App\Domain\ErweiterteSuche::OHNE_FAMILIE => 'Ohne Familie',
                        ...array_column(
                            array_map(static fn($r): array => [$r->value, 'Rolle: ' . $r->bezeichnung()], \App\Domain\FamilienRolle::cases()),
                            1,
                            0,
                        ),
                    ], 'Alle') ?>
                    <?= $bereich('alter', 'Alter (heute)', 'alter', isset($e->alterVon) ? (string) $e->alterVon : null, isset($e->alterBis) ? (string) $e->alterBis : null, 'number') ?>
                    <?= $bereich('geburt', 'Geburtsdatum', 'datum', $e->geburtVon, $e->geburtBis) ?>
                    <?= $bereich('eintritt', 'Eintritt', 'datum', $e->eintrittVon, $e->eintrittBis) ?>
                    <?= $bereich('austritt', 'Austritt', 'datum', $e->austrittVon, $e->austrittBis) ?>
                </div>
                <p class="feld-hinweis suche-erweitert-hinweis" id="suche-erweitert-hinweis">Alle ausgefüllten Felder müssen zugleich passen, auch zusammen mit dem Suchtext. Texte finden auch Teilstücke, Groß- und Kleinschreibung ist egal. Wer einen Austritt sucht, sieht automatisch auch Ehemalige.</p>
            </details>
        </form>

        <?php /* The live region; a search updates its text (ergebnis.php). */ ?>
        <p class="nur-lesehilfe" id="suche-stand" role="status"><?= e($ergebnis->stand()) ?></p>

        <div id="mitglieder-ergebnis">
            <?php require __DIR__ . '/ergebnis.php'; ?>
        </div>
    </div>

    <?= $ansicht->html ?>
</div>
