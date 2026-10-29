<?php

/**
 * The member form (docs/spec/07-mitglieder.md section 1): every field in
 * the groups of the spec, one form, saved with one button. Shown in the tab
 * "Daten" and on the page "Neues Mitglied"; markup from the design system
 * (formular-zweispaltig, feld, feldgruppe, formular-aktionen).
 *
 * Only Mitgliedsnummer and Nachname are required. Hints below a field say
 * what an empty field means instead of forcing a value.
 *
 * @var \App\App\Mitglied\MitgliedFormular $formular
 * @var \App\App\Mitglied\Auswahllisten $listen
 * @var \App\Domain\Mitglied|null $mitglied the stored member, null for a new one
 * @var \App\Domain\Familie|null $familie
 * @var string $aktion
 * @var string $abbrechen
 * @var bool $darfLoeschen
 * @var string|null $loeschAdresse
 * @var string|null $csrf
 */

use App\App\Mitglied\MitgliedFormular;
use App\Domain\Geschlecht;
use App\Domain\Zahlart;
use App\Support\Iban;

$neu = $mitglied?->id === null;

/** id, aria-describedby and aria-invalid of a field; $hilfe = id of a help text. */
$attribute = static function (string $feld, ?string $hilfe = null) use ($formular): string {
    $beschreibung = array_filter([
        $formular->fehler($feld) !== null ? 'm-' . $feld . '-fehler' : null,
        $hilfe,
    ]);

    return 'id="m-' . e($feld) . '" name="' . e($feld) . '"'
        . ($beschreibung === [] ? '' : ' aria-describedby="' . e(implode(' ', $beschreibung)) . '"')
        . ($formular->fehler($feld) !== null ? ' aria-invalid="true"' : '');
};
$fehler = static function (string $feld) use ($formular): string {
    $text = $formular->fehler($feld);

    return $text === null ? '' : '<p class="feld-fehler" id="m-' . e($feld) . '-fehler">' . e($text) . '</p>';
};
/** A plain text field; $mehr = further attributes, already escaped. */
$text = static function (string $feld, string $label, string $klasse = '', ?string $hilfe = null, string $mehr = '') use ($formular, $attribute, $fehler): string {
    $hilfeId = $hilfe === null ? null : 'm-' . $feld . '-hilfe';
    $laenge = MitgliedFormular::LAENGEN[$feld] ?? null;

    return '<div class="feld' . ($klasse === '' ? '' : ' ' . e($klasse)) . '">'
        . '<label for="m-' . e($feld) . '">' . e($label) . '</label>'
        . '<input type="text" ' . $attribute($feld, $hilfeId) . ' value="' . e($formular->wert($feld)) . '"'
        . ($laenge === null ? '' : ' maxlength="' . $laenge . '"') . ' autocomplete="off"' . ($mehr === '' ? '' : ' ' . $mehr) . '>'
        . $fehler($feld)
        . ($hilfe === null ? '' : '<p class="feld-hinweis" id="' . e($hilfeId) . '">' . e($hilfe) . '</p>')
        . '</div>';
};
/** A date field: the browser's date picker, or plain typing where it has none. */
$datum = static function (string $feld, string $label, ?string $hilfe = null) use ($formular, $attribute, $fehler): string {
    $hilfeId = $hilfe === null ? null : 'm-' . $feld . '-hilfe';

    return '<div class="feld feld-kurz">'
        . '<label for="m-' . e($feld) . '">' . e($label) . '</label>'
        . '<input type="date" ' . $attribute($feld, $hilfeId) . ' value="' . e($formular->wert($feld)) . '">'
        . $fehler($feld)
        . ($hilfe === null ? '' : '<p class="feld-hinweis" id="' . e($hilfeId) . '">' . e($hilfe) . '</p>')
        . '</div>';
};

$abteilungen = $listen->abteilungenFuer($mitglied?->abteilungIds ?? []);
$funktionen = $listen->funktionenFuer($mitglied?->funktionIds ?? []);
$beitragsarten = $listen->beitragsartenFuer($mitglied?->beitragsartId);
$nummer = $formular->wert('mitgliedsnummer');
$ibanUngueltig = $formular->wert('iban') !== '' && !Iban::istGueltig($formular->wert('iban'));

$mandatsdatumDefault = $formular->wert('eintrittsdatum') !== '' ? null : $listen->vereinsMandatsdatum;
$mandatsdatumHilfe = match (true) {
    $formular->wert('eintrittsdatum') !== '' => 'Leer lassen: es gilt das Eintrittsdatum.',
    $mandatsdatumDefault !== null => 'Leer lassen: es gilt das Standard-Mandatsdatum aus den Vereinsdaten, ' . datum($mandatsdatumDefault) . '.',
    default => 'Leer lassen: es gilt das Eintrittsdatum oder das Standard-Mandatsdatum aus den Vereinsdaten.',
};
$lizenzFunktionen = $listen->funktionenMitLizenz();
?>
<form class="formular formular-zweispaltig mitglied-formular" id="mitglied-formular" method="post" action="<?= e($aktion) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
    <?php if (!$neu): ?>
        <input type="hidden" name="version" value="<?= e($mitglied->version) ?>">
    <?php endif; ?>

    <?php if ($formular->fehler !== []): ?>
        <div class="hinweis hinweis-fehler" role="alert">
            <strong>Das Mitglied ist noch nicht gespeichert.</strong>
            <ul>
                <?php foreach ($formular->fehler as $feld => $meldung): ?>
                    <li><a href="#m-<?= e($feld) ?>"><?= e($meldung) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h3>Person</h3>
    <?= $text('mitgliedsnummer', 'Mitgliedsnummer', 'feld-kurz', $neu ? 'Die nächste freie Nummer ist eingetragen; Sie können sie ändern.' : null, 'inputmode="numeric" required') ?>
    <div class="feld feld-kurz">
        <label for="m-geschlecht">Geschlecht</label>
        <select <?= $attribute('geschlecht') ?>>
            <option value="">nicht angegeben</option>
            <?php foreach (Geschlecht::cases() as $geschlecht): ?>
                <option value="<?= e($geschlecht->value) ?>"<?= $formular->wert('geschlecht') === $geschlecht->value ? ' selected' : '' ?>><?= e($geschlecht->bezeichnung()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= $text('anrede', 'Anrede') ?>
    <?= $text('titel', 'Titel') ?>
    <?= $text('vorname', 'Vorname') ?>
    <?= $text('nachname', 'Nachname', '', null, 'required') ?>
    <?= $text('namenszusatz', 'Namenszusatz') ?>
    <?= $datum('geburtsdatum', 'Geburtsdatum') ?>

    <h3>Anschrift</h3>
    <?= $text('strasse', 'Straße und Hausnummer', 'feld-breit') ?>
    <?= $text('plz', 'PLZ', 'feld-kurz', null, 'inputmode="numeric"') ?>
    <?= $text('ort', 'Ort') ?>
    <?= $text('land', 'Land', 'feld-kurz', 'Leer lassen für Deutschland (D).') ?>

    <h3>Kontakt</h3>
    <?= $text('telefon', 'Telefon', '', null, 'inputmode="tel"') ?>
    <?= $text('handy', 'Handy', '', null, 'inputmode="tel"') ?>
    <?= $text('email', 'E-Mail', 'feld-breit', null, 'inputmode="email"') ?>

    <h3>Mitgliedschaft</h3>
    <fieldset class="feldgruppe">
        <legend>Status</legend>
        <label class="feld-haken"><input type="radio" name="aktiv" value="1"<?= $formular->wert('aktiv') !== '0' ? ' checked' : '' ?>> aktiv</label>
        <label class="feld-haken"><input type="radio" name="aktiv" value="0"<?= $formular->wert('aktiv') === '0' ? ' checked' : '' ?>> passiv</label>
    </fieldset>
    <fieldset class="feldgruppe">
        <legend>Abteilungen</legend>
        <?php if ($abteilungen === []): ?>
            <p class="feld-hinweis">Noch keine Abteilungen angelegt (Einstellungen, Abschnitt Abteilungen).</p>
        <?php endif; ?>
        <?php foreach ($abteilungen as $abteilung): ?>
            <label class="feld-haken"><input type="checkbox" name="abteilungen[]" value="<?= e($abteilung->id) ?>"<?= in_array($abteilung->id, $formular->abteilungIds, true) ? ' checked' : '' ?>> <?= e($abteilung->name) ?><?= $abteilung->aktiv ? '' : ' (nicht mehr aktiv)' ?></label>
        <?php endforeach; ?>
    </fieldset>
    <?= $datum('eintrittsdatum', 'Eintrittsdatum', 'Leer lassen, wenn es unbekannt ist.') ?>
    <?= $datum('austrittsdatum', 'Austrittsdatum', 'Ab diesem Tag zählt das Mitglied als ehemalig.') ?>
    <?= $text('austrittsgrund', 'Austrittsgrund', 'feld-breit', null, 'list="m-austrittsgruende"') ?>
    <datalist id="m-austrittsgruende">
        <?php foreach (MitgliedFormular::AUSTRITTSGRUENDE as $grund): ?>
            <option value="<?= e($grund) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <h3>Beitrag</h3>
    <div class="feld">
        <label for="m-beitragsart_id">Beitragsart</label>
        <select <?= $attribute('beitragsart_id', 'm-beitragsart_id-hilfe') ?>>
            <option value="">keine</option>
            <?php foreach ($beitragsarten as $beitragsart): ?>
                <option value="<?= e($beitragsart->id) ?>"<?= $formular->wert('beitragsart_id') === (string) $beitragsart->id ? ' selected' : '' ?>><?= e($beitragsart->name . ' – ' . geld($beitragsart->betragCent)) ?><?= $beitragsart->aktiv ? '' : ' (nicht mehr aktiv)' ?></option>
            <?php endforeach; ?>
        </select>
        <p class="feld-hinweis" id="m-beitragsart_id-hilfe">Eltern und Kinder einer Familie haben meist keine eigene; es zahlt der Zahler.</p>
    </div>
    <fieldset class="feldgruppe">
        <legend>Zahlart</legend>
        <?php foreach (Zahlart::cases() as $zahlart): ?>
            <label class="feld-haken"><input type="radio" name="zahlart" value="<?= e($zahlart->value) ?>"<?= ($formular->wert('zahlart') === '' ? Zahlart::Lastschrift->value : $formular->wert('zahlart')) === $zahlart->value ? ' checked' : '' ?>> <?= e($zahlart->bezeichnung()) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <div class="feld">
        <label for="m-iban">IBAN</label>
        <input type="text" id="m-iban" name="iban" value="<?= e($formular->wert('iban')) ?>" maxlength="<?= MitgliedFormular::LAENGEN['iban'] ?>" autocomplete="off" spellcheck="false"<?= $ibanUngueltig ? ' aria-invalid="true" aria-describedby="m-iban-hinweis"' : '' ?>>
        <?php if ($ibanUngueltig): ?>
            <p class="feld-fehler" id="m-iban-hinweis">Die Prüfziffer passt nicht. Bitte die IBAN vom Kontoauszug abschreiben – gespeichert wird sie trotzdem.</p>
        <?php endif; ?>
    </div>
    <?= $text('bic', 'BIC', 'feld-kurz', 'Nur nötig für Konten im Ausland.', 'spellcheck="false"') ?>
    <?= $text('kontoinhaber', 'Kontoinhaber', 'feld-breit', 'Nur eintragen, wenn das Konto nicht dem Mitglied gehört.') ?>
    <?= $text('mandatsreferenz', 'Mandatsreferenz', '', 'Leer lassen: es gilt die Mitgliedsnummer' . ($nummer !== '' && ctype_digit($nummer) ? ' (' . (int) $nummer . ')' : '') . '.') ?>
    <?= $datum('mandatsdatum', 'Mandatsdatum', $mandatsdatumHilfe) ?>

    <h3>Funktionen</h3>
    <fieldset class="feldgruppe">
        <legend>Funktionen im Verein</legend>
        <?php if ($funktionen === []): ?>
            <p class="feld-hinweis">Noch keine Funktionen angelegt (Einstellungen, Abschnitt Funktionen).</p>
        <?php endif; ?>
        <?php foreach ($funktionen as $funktion): ?>
            <label class="feld-haken"><input type="checkbox" name="funktionen[]" value="<?= e($funktion->id) ?>"<?= in_array($funktion->id, $formular->funktionIds, true) ? ' checked' : '' ?>> <?= e($funktion->name) ?><?= $funktion->aktiv ? '' : ' (nicht mehr aktiv)' ?></label>
        <?php endforeach; ?>
    </fieldset>
    <?= $text('lizenznummer', 'Lizenznummer', '', $lizenzFunktionen === [] ? null : 'Für ' . implode(', ', $lizenzFunktionen) . '.') ?>

    <h3>Sonstiges</h3>
    <div class="feld feld-breit">
        <label for="m-bemerkung">Bemerkung</label>
        <textarea id="m-bemerkung" name="bemerkung" rows="4" maxlength="<?= MitgliedFormular::LAENGEN['bemerkung'] ?>"><?= e($formular->wert('bemerkung')) ?></textarea>
    </div>

    <div class="formular-aktionen">
        <button type="submit" class="knopf knopf-haupt"><?= $neu ? 'Mitglied anlegen' : 'Mitglied speichern' ?></button>
        <a class="knopf knopf-still" href="<?= e($abbrechen) ?>"><?= $neu ? 'Abbrechen' : 'Änderungen verwerfen' ?></a>
    </div>
</form>

<?php if ($darfLoeschen && $loeschAdresse !== null): ?>
    <p class="knopfreihe">
        <a class="knopf knopf-gefahr" href="<?= e($loeschAdresse) ?>">Mitglied löschen</a>
    </p>
<?php endif; ?>
