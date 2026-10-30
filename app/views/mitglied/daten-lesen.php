<?php

/**
 * The tab "Daten" for an account that may not change members (role "Nur
 * lesen", issue #87): the same groups as the form (mitglied/formular.php),
 * as label and value (design system "angaben") - no fields, no buttons.
 * An empty field is left out; a group without any value says so.
 *
 * @var \App\Domain\Mitglied $mitglied
 * @var \App\Domain\Familie|null $familie
 * @var \App\App\Mitglied\Auswahllisten $listen
 * @var \App\Service\Karte\Wohnort|null $wohnort the block "Wohnort auf der Karte" (issue #106), without buttons
 */

/** Names of the chosen entries, in the order of the settings. */
$namen = static function (array $ids, array $liste): string {
    $gewaehlt = array_filter($liste, static fn(object $eintrag): bool => in_array($eintrag->id, $ids, true));

    return implode(', ', array_map(static fn(object $eintrag): string => $eintrag->name, $gewaehlt));
};
$beitragsart = '';
foreach ($listen->beitragsarten as $art) {
    if ($art->id === $mitglied->beitragsartId) {
        $beitragsart = $art->name . ' – ' . geld($art->betragCent);
    }
}

$gruppen = [
    'Person' => [
        'Mitgliedsnummer' => (string) $mitglied->mitgliedsnummer,
        'Geschlecht' => $mitglied->geschlecht?->bezeichnung() ?? '',
        'Anrede' => $mitglied->anrede,
        'Titel' => $mitglied->titel,
        'Vorname' => $mitglied->vorname,
        'Nachname' => $mitglied->nachname,
        'Namenszusatz' => $mitglied->namenszusatz,
        'Geburtsdatum' => datum($mitglied->geburtsdatum),
    ],
    'Anschrift' => [
        'Straße und Hausnummer' => $mitglied->strasse,
        'PLZ und Ort' => trim($mitglied->plz . ' ' . $mitglied->ort),
        'Land' => $mitglied->land,
    ],
    'Kontakt' => [
        'Telefon' => $mitglied->telefon,
        'Handy' => $mitglied->handy,
        'E-Mail' => $mitglied->email,
    ],
    'Mitgliedschaft' => [
        'Status' => $mitglied->aktiv ? 'aktiv' : 'passiv',
        'Abteilungen' => $namen($mitglied->abteilungIds, $listen->abteilungen),
        'Familie' => $familie === null ? '' : trim($familie->name . ($mitglied->familieRolle !== null ? ' (' . $mitglied->familieRolle->bezeichnung() . ')' : '')),
        'Eintrittsdatum' => datum($mitglied->eintrittsdatum),
        'Austrittsdatum' => datum($mitglied->austrittsdatum),
        'Austrittsgrund' => $mitglied->austrittsgrund,
    ],
    'Beitrag' => [
        'Beitragsart' => $beitragsart,
        'Zahlart' => $mitglied->zahlart->bezeichnung(),
        'IBAN' => $mitglied->iban,
        'BIC' => $mitglied->bic,
        'Kontoinhaber' => $mitglied->kontoinhaber,
        'Mandatsreferenz' => $mitglied->effektiveMandatsreferenz(),
        'Mandatsdatum' => datum($mitglied->effektivesMandatsdatum($listen->vereinsMandatsdatum)),
    ],
    'Funktionen' => [
        'Funktionen im Verein' => $namen($mitglied->funktionIds, $listen->funktionen),
        'Lizenznummer' => $mitglied->lizenznummer,
    ],
    'Sonstiges' => [
        'Bemerkung' => $mitglied->bemerkung,
    ],
];
?>
<div class="mitglied-daten-lesen">
    <p class="hinweis hinweis-info">Sie können dieses Mitglied ansehen, aber nicht ändern.</p>
    <?php foreach ($gruppen as $gruppe => $felder): $felder = array_filter($felder, static fn(string $wert): bool => $wert !== ''); ?>
        <h3><?= e($gruppe) ?></h3>
        <?php if ($felder === []): ?>
            <p class="gedaempft">Keine Angaben.</p>
        <?php else: ?>
            <dl class="angaben">
                <?php foreach ($felder as $bezeichnung => $wert): ?>
                    <dt><?= e($bezeichnung) ?></dt>
                    <dd<?= $bezeichnung === 'Bemerkung' ? ' class="mehrzeilig"' : '' ?>><?= e($wert) ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <?php if ($gruppe === 'Anschrift' && isset($wohnort)): ?>
            <?php $wohnortFormular = null; require __DIR__ . '/wohnort.php'; ?>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
