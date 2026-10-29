<?php

/**
 * Settings section Verein: club data, associations, logo
 * (docs/spec/11-vereinsdaten.md).
 *
 * @var \App\Service\Verein\Vereinsdaten $verein
 * @var array<string, string> $felder key => label
 * @var list<\App\Domain\Verband> $verbaende
 * @var int|null $logoVersion upload time of the logo, null without one
 * @var string $csrf
 */

// Field groups of the form, in the order of the spec; the type is the input type.
$gruppen = [
    'Name' => ['vereinsname' => 'text', 'kurzname' => 'text'],
    'Anschrift' => ['strasse' => 'text', 'plz' => 'text', 'ort' => 'text'],
    'Kontakt' => ['telefon' => 'tel', 'email' => 'email', 'website' => 'url'],
    'Register' => ['registergericht' => 'text', 'registernummer' => 'text'],
    'Steuer' => ['finanzamt' => 'text', 'steuernummer' => 'text', 'freistellungsdatum' => 'date'],
    'Bankverbindung' => ['kontoinhaber' => 'text', 'iban' => 'text', 'bic' => 'text', 'bankname' => 'text', 'glaeubiger_id' => 'text'],
    'Lastschrift' => ['mandatsdatum' => 'date', 'verwendungszweck' => 'text'],
];
$hinweise = [
    'kurzname' => 'Für die Kopfzeile und Dateinamen.',
    'iban' => 'Leerzeichen sind erlaubt, sie werden entfernt.',
    'glaeubiger_id' => 'Ohne IBAN und Gläubiger-ID kann keine Lastschriftdatei erzeugt werden.',
    'mandatsdatum' => 'Wird bei Mitgliedern ohne eigenes Datum verwendet.',
    'verwendungszweck' => 'Platzhalter siehe Seite Beiträge.',
];
?>
<form method="post" action="/admin/verein" class="formular formular-verein">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <?php foreach ($gruppen as $titel => $gruppe): ?>
        <fieldset class="feldgruppe">
            <legend><?= e($titel) ?></legend>
            <?php foreach ($gruppe as $schluessel => $typ): ?>
                <div class="feld">
                    <label for="verein-<?= e($schluessel) ?>"><?= e($felder[$schluessel]) ?></label>
                    <input id="verein-<?= e($schluessel) ?>" name="<?= e($schluessel) ?>" type="<?= e($typ) ?>" value="<?= e($verein->wert($schluessel)) ?>"<?= isset($hinweise[$schluessel]) ? ' aria-describedby="verein-' . e($schluessel) . '-hinweis"' : '' ?><?= $schluessel === 'iban' ? ' autocomplete="off" spellcheck="false"' : '' ?>>
                    <?php if (isset($hinweise[$schluessel])): ?>
                        <p id="verein-<?= e($schluessel) ?>-hinweis" class="feld-hinweis"><?= e($hinweise[$schluessel]) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <button type="submit" class="knopf knopf-haupt">Vereinsdaten speichern</button>
</form>

<h3>Verbände</h3>
<p class="feld-hinweis">Zum Beispiel BLSV, BFV oder Kegelverband mit der Vereinsnummer dort. Die Reihenfolge richtet sich nach der Position. Eine Zeile ohne Verbandsname wird gelöscht.</p>
<form method="post" action="/admin/verein/verbaende" class="formular formular-verbaende">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <?php
    $zeilen = array_map(static fn(\App\Domain\Verband $v): array => [
        'id' => (string) $v->id, 'name' => $v->name, 'vereinsnummer' => $v->vereinsnummer, 'bemerkung' => $v->bemerkung,
    ], $verbaende);
    // Two empty rows to add to; saving drops the ones left empty.
    $zeilen[] = ['id' => '', 'name' => '', 'vereinsnummer' => '', 'bemerkung' => ''];
    $zeilen[] = ['id' => '', 'name' => '', 'vereinsnummer' => '', 'bemerkung' => ''];
    ?>
    <?php foreach ($zeilen as $i => $zeile): ?>
        <fieldset class="feldgruppe verband-zeile">
            <legend><?= $zeile['id'] === '' ? 'Neuer Verband' : e($zeile['name']) ?></legend>
            <input type="hidden" name="verband[<?= e($i) ?>][id]" value="<?= e($zeile['id']) ?>">
            <div class="feld">
                <label for="verband-<?= e($i) ?>-name">Verband</label>
                <input id="verband-<?= e($i) ?>-name" name="verband[<?= e($i) ?>][name]" type="text" value="<?= e($zeile['name']) ?>" maxlength="120">
            </div>
            <div class="feld">
                <label for="verband-<?= e($i) ?>-nummer">Vereinsnummer</label>
                <input id="verband-<?= e($i) ?>-nummer" name="verband[<?= e($i) ?>][vereinsnummer]" type="text" value="<?= e($zeile['vereinsnummer']) ?>" maxlength="60">
            </div>
            <div class="feld">
                <label for="verband-<?= e($i) ?>-bemerkung">Bemerkung</label>
                <input id="verband-<?= e($i) ?>-bemerkung" name="verband[<?= e($i) ?>][bemerkung]" type="text" value="<?= e($zeile['bemerkung']) ?>" maxlength="255">
            </div>
            <div class="feld">
                <label for="verband-<?= e($i) ?>-position">Position</label>
                <input id="verband-<?= e($i) ?>-position" name="verband[<?= e($i) ?>][position]" type="number" min="1" value="<?= e($i + 1) ?>">
            </div>
        </fieldset>
    <?php endforeach; ?>

    <button type="submit" class="knopf knopf-haupt">Verbände speichern</button>
</form>

<h3>Logo</h3>
<div class="logo-verwaltung">
    <img class="logo-vorschau" src="<?= $logoVersion !== null ? '/logo/anmeldung?v=' . e($logoVersion) : \App\View\View::LOGO_PLATZHALTER ?>" alt="<?= $logoVersion !== null ? 'Aktuelles Logo' : 'Platzhalter, es ist noch kein Logo hochgeladen' ?>">
    <form method="post" action="/admin/verein/logo" enctype="multipart/form-data" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="verein-logo">Logo hochladen</label>
            <input id="verein-logo" name="logo" type="file" accept="image/png,image/jpeg" required aria-describedby="verein-logo-hinweis">
            <p id="verein-logo-hinweis" class="feld-hinweis">PNG oder JPEG, höchstens 5 MB. Es erscheint in der Kopfzeile, auf der Anmeldeseite und als Symbol im Browser.</p>
        </div>
        <button type="submit" class="knopf knopf-haupt">Logo hochladen</button>
    </form>
    <?php if ($logoVersion !== null): ?>
        <form method="post" action="/admin/verein/logo/entfernen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knopf">Logo entfernen</button>
        </form>
    <?php endif; ?>
</div>
