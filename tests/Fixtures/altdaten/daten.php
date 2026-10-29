<?php

declare(strict_types=1);

/**
 * INVENTED sample of the old member software's export - fantasy names,
 * addresses and IBANs (the IBANs are the well-known documentation examples).
 * No real data, ever (CLAUDE.md section 1). Same column names as the real
 * export, but only a subset of its 167 columns plus a few that must be
 * ignored. erzeugen.php turns this into mitglieder.xlsx and familien.xlsx.
 *
 * @return array{mitglieder: list<list<string|int|null>>, familien: list<list<string|int|null>>}
 */

$spalten = [
    'LfdNr', 'Adressnummer', 'Anrede', 'Titel', 'Vorname', 'Nachname', 'Geschlecht', 'GebDatum', 'AlterInJahren',
    'Strasse', 'Plz', 'Ort', 'Land', 'Lkz', 'Telefon', 'Handy', 'EMail', 'Fax',
    'Konto1Iban', 'Konto1Bic', 'Konto2Iban', 'ZahlerAdressNummer', 'ZahlerVorname', 'ZahlerNachname', 'MitZahlerIban',
    'EintrittsDatum', 'AustrittsDatum', 'Austrittsgrund', 'Zahlart',
    'Abteilungsnummer (alle)(1)', 'Beitragsname (alle)(1)',
    'Abteilungsnummer (alle aktuell)(1)', 'Abteilungsname (alle aktuell)(1)', 'Beitragsname (alle aktuell)(1)',
    'Abteilungsnummer (alle aktuell)(2)', 'Abteilungsname (alle aktuell)(2)', 'Beitragsname (alle aktuell)(2)',
    'Abteilungsnummer (alle aktuell)(3)', 'Abteilungsname (alle aktuell)(3)', 'Beitragsname (alle aktuell)(3)',
    'UelLizenzNr', 'UelGueltigBis', 'AdrNotizen', 'MitNotizen', 'Multifeld 1', 'Wahlfeld1', 'Helfer', 'Mahnstufe',
];

/**
 * @param array<string, string|int|null> $werte
 * @return list<string|int|null>
 */
$zeile = static function (array $werte) use ($spalten): array {
    $standard = [
        'Anrede' => 'Herr', 'Land' => 'Deutschland', 'Lkz' => 'D', 'Ort' => 'Beispielstadt', 'Plz' => 80331,
        'Strasse' => 'Musterweg 1', 'Zahlart' => 'Lastschriftzahler', 'EintrittsDatum' => '01.04.2010',
        'Mahnstufe' => 0, 'Helfer' => 0,
    ];
    $werte += $standard;
    // Number cells like the old export: Adressnummer and Plz are numbers.
    return array_map(static fn(string $spalte): string|int|null => $werte[$spalte] ?? null, $spalten);
};

// Current department slots: [Nr, Name, Beitrag] x up to 3.
$mitglieder = [
    $spalten,
    // The 12 combinations of docs/fachlichkeit.md section 8.
    $zeile(['Adressnummer' => 1001, 'Vorname' => 'Erika', 'Nachname' => 'Beispiel', 'Geschlecht' => 'w', 'GebDatum' => '12.03.1980',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Konto1Bic' => 'COBADEFFXXX', 'EMail' => 'erika@example.org',
        'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene',
        'Abteilungsnummer (alle aktuell)(2)' => 9, 'Abteilungsname (alle aktuell)(2)' => 'Fußball', 'Beitragsname (alle aktuell)(2)' => 'aktiv',
        'Abteilungsnummer (alle)(1)' => 1, 'Beitragsname (alle)(1)' => 'Kinder']),
    $zeile(['Adressnummer' => 1002, 'Vorname' => 'Fritz', 'Nachname' => 'Fantasie', 'Geschlecht' => 'm', 'GebDatum' => '05.11.1975',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Abteilungsnummer (alle aktuell)(1)' => 1, 'Abteilungsname (alle aktuell)(1)' => 'Badminton',
        'Beitragsname (alle aktuell)(1)' => 'Erwachsene']),
    $zeile(['Adressnummer' => 1003, 'Vorname' => 'Kai', 'Nachname' => 'Kleinfeld', 'Geschlecht' => 'm', 'GebDatum' => '20.06.2015',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Kinder']),
    $zeile(['Adressnummer' => 1004, 'Vorname' => 'Jana', 'Nachname' => 'Jugendlich', 'Geschlecht' => 'w', 'GebDatum' => '02.02.2009',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Jugendliche']),
    $zeile(['Adressnummer' => 1005, 'Vorname' => 'Rudi', 'Nachname' => 'Ruhestand', 'Geschlecht' => 'm', 'GebDatum' => '30.09.1950',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 16, 'Abteilungsname (alle aktuell)(1)' => 'Kegeln',
        'Beitragsname (alle aktuell)(1)' => 'Rentner', 'Abteilungsnummer (alle aktuell)(2)' => 16, 'Abteilungsname (alle aktuell)(2)' => 'Kegeln',
        'Beitragsname (alle aktuell)(2)' => 'aktiv']),
    $zeile(['Adressnummer' => 1006, 'Vorname' => 'Rosa', 'Nachname' => 'Ruhestand', 'Geschlecht' => 'w', 'GebDatum' => '14.01.1948',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 16, 'Abteilungsname (alle aktuell)(1)' => 'Kegeln',
        'Beitragsname (alle aktuell)(1)' => 'Rentner']),
    // Family 1: payer (Familienkarte), adult with "Beitragsfrei FK", child with "Beitragsfrei FK".
    $zeile(['Adressnummer' => 1007, 'Vorname' => 'Paula', 'Nachname' => 'Familie', 'Geschlecht' => 'w', 'GebDatum' => '08.08.1982',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Abteilungsnummer (alle aktuell)(1)' => 34, 'Abteilungsname (alle aktuell)(1)' => 'Turnen',
        'Beitragsname (alle aktuell)(1)' => 'Familienkarte', 'AdrNotizen' => 'Gymnastik aktiv', 'MitNotizen' => 'Gymnastik aktiv']),
    $zeile(['Adressnummer' => 1008, 'Vorname' => 'Peter', 'Nachname' => 'Familie', 'Geschlecht' => 'm', 'GebDatum' => '17.04.1980',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Abteilungsnummer (alle aktuell)(1)' => 34, 'Abteilungsname (alle aktuell)(1)' => 'Turnen',
        'Beitragsname (alle aktuell)(1)' => 'Beitragsfrei FK', 'MitNotizen' => 'Gymnastik-Passiv']),
    $zeile(['Adressnummer' => 1009, 'Vorname' => 'Pia', 'Nachname' => 'Familie', 'Geschlecht' => 'w', 'GebDatum' => '23.12.2012',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Beitragsfrei FK', 'MitNotizen' => 'Fußball-Aktiv']),
    $zeile(['Adressnummer' => 1010, 'Vorname' => 'Mia', 'Nachname' => 'Minimal', 'Geschlecht' => 'w', 'GebDatum' => '01.07.1990',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Mindestbeitrag', 'Abteilungsnummer (alle aktuell)(2)' => 9, 'Abteilungsname (alle aktuell)(2)' => 'Fußball',
        'Beitragsname (alle aktuell)(2)' => 'aktiv']),
    $zeile(['Adressnummer' => 1011, 'Vorname' => 'Max', 'Nachname' => 'Minimal', 'Geschlecht' => 'm', 'GebDatum' => '09.09.1988',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 16, 'Abteilungsname (alle aktuell)(1)' => 'Kegeln',
        'Beitragsname (alle aktuell)(1)' => 'Mindestbeitrag']),
    $zeile(['Adressnummer' => 1012, 'Anrede' => 'Herr', 'Titel' => 'Dr.', 'Vorname' => 'Ehrenfried', 'Nachname' => 'Ehrenwert', 'Geschlecht' => 'm',
        'GebDatum' => '03.03.1955', 'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 16,
        'Abteilungsname (alle aktuell)(1)' => 'Kegeln', 'Beitragsname (alle aktuell)(1)' => 'Beitragsfrei', 'MitNotizen' => 'Ehrenmitglied']),
    // Somebody else pays: 1013 is a pure payer address (no department), 1014 pays through him/her.
    $zeile(['Adressnummer' => 1013, 'Anrede' => 'Frau', 'Vorname' => 'Zora', 'Nachname' => 'Zahlerin', 'Geschlecht' => 'w', 'GebDatum' => '11.11.1970',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51', 'Konto1Bic' => 'BYLADEM1001']),
    $zeile(['Adressnummer' => 1014, 'Vorname' => 'Tom', 'Nachname' => 'Zahlerin', 'Geschlecht' => 'm', 'GebDatum' => '04.05.2008',
        'ZahlerAdressNummer' => 1013, 'ZahlerVorname' => 'Zora', 'ZahlerNachname' => 'Zahlerin', 'MitZahlerIban' => 'DE02 1203 0000 0000 2020 51',
        'Abteilungsnummer (alle aktuell)(1)' => 1, 'Abteilungsname (alle aktuell)(1)' => 'Badminton', 'Beitragsname (alle aktuell)(1)' => 'Kinder']),
    // Two departments, "Turnen" -> BLSV 34.
    $zeile(['Adressnummer' => 1015, 'Vorname' => 'Dora', 'Nachname' => 'Doppelt', 'Geschlecht' => 'w', 'GebDatum' => '19.02.1985',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Telefon' => '0711 123456', 'Handy' => '0170 1234567',
        'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene',
        'Abteilungsnummer (alle aktuell)(2)' => 34, 'Abteilungsname (alle aktuell)(2)' => 'Turnen', 'Beitragsname (alle aktuell)(2)' => 'Erwachsene']),
    // Invoice payer without IBAN, trainer with licence number.
    $zeile(['Adressnummer' => 1016, 'Vorname' => 'Uwe', 'Nachname' => 'Uebungsleiter', 'Geschlecht' => 'm', 'GebDatum' => '27.07.1972',
        'Zahlart' => 'Rechnungszahler', 'UelLizenzNr' => 'C-00815', 'UelGueltigBis' => '31.12.2027',
        'Abteilungsnummer (alle aktuell)(1)' => 16, 'Abteilungsname (alle aktuell)(1)' => 'Kegeln', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene',
        'Abteilungsnummer (alle aktuell)(2)' => 16, 'Abteilungsname (alle aktuell)(2)' => 'Kegeln', 'Beitragsname (alle aktuell)(2)' => 'aktiv',
        'AdrNotizen' => 'Übungsleiter Kegeln']),
    // Placeholder entry date, unreadable birth date.
    $zeile(['Adressnummer' => 1017, 'Vorname' => 'Paul', 'Nachname' => 'Platzhalter', 'Geschlecht' => 'm', 'GebDatum' => '31.02.1990',
        'EintrittsDatum' => '01.01.1901', 'Konto1Iban' => 'DE89 3704 0044 0532 0130 00',
        'Abteilungsnummer (alle aktuell)(1)' => 1, 'Abteilungsname (alle aktuell)(1)' => 'Badminton', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene']),
    // Spouse pays: payer 1001 is a member itself, the Zahler* names are filled.
    $zeile(['Adressnummer' => 1018, 'Vorname' => 'Egon', 'Nachname' => 'Beispiel', 'Geschlecht' => 'm', 'GebDatum' => '21.10.1978',
        'ZahlerAdressNummer' => 1001, 'ZahlerVorname' => 'Erika', 'ZahlerNachname' => 'Beispiel', 'MitZahlerIban' => 'DE89 3704 0044 0532 0130 00',
        'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene']),
    // Hints: Familienkarte and Beitragsfrei FK without family.
    $zeile(['Adressnummer' => 1019, 'Vorname' => 'Fiona', 'Nachname' => 'Ohnefamilie', 'Geschlecht' => 'w', 'GebDatum' => '06.06.1983',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Familienkarte', 'MitNotizen' => 'Fußball-Passiv']),
    $zeile(['Adressnummer' => 1020, 'Vorname' => 'Finn', 'Nachname' => 'Ohnefamilie', 'Geschlecht' => 'm', 'GebDatum' => '15.05.2011',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Beitragsfrei FK', 'MitNotizen' => 'inaktiv seit Jahren']),
    // Left the club.
    $zeile(['Adressnummer' => 1021, 'Vorname' => 'Anton', 'Nachname' => 'Ausgetreten', 'Geschlecht' => 'm', 'GebDatum' => '28.08.1965',
        'AustrittsDatum' => '31.12.2025', 'Austrittsgrund' => 'Umzug', 'Konto1Iban' => 'DE89 3704 0044 0532 0130 00',
        'Abteilungsnummer (alle aktuell)(1)' => 1, 'Abteilungsname (alle aktuell)(1)' => 'Badminton', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene']),
    // Notes with line break, semicolon and quotes; 4-digit postcode lost its zero.
    $zeile(['Adressnummer' => 1022, 'Vorname' => 'Nora', 'Nachname' => 'Notiz', 'Geschlecht' => 'w', 'GebDatum' => '10.10.1991', 'Plz' => 1067,
        'Ort' => 'Dresdenhausen', 'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'ZahlerAdressNummer' => 1023,
        'Abteilungsnummer (alle aktuell)(1)' => 34, 'Abteilungsname (alle aktuell)(1)' => 'Turnen', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene',
        'AdrNotizen' => "Zeile eins\r\nZeile zwei; mit \"Zitat\"", 'MitNotizen' => 'Wirtschaftshelfer']),
    // Pure payer without Zahler* names on the member's row: name comes from this row.
    $zeile(['Adressnummer' => 1023, 'Anrede' => 'Herr', 'Vorname' => 'Zeno', 'Nachname' => 'Zahler', 'Geschlecht' => 'm',
        'Konto1Iban' => 'DE02 1203 0000 0000 2020 51']),
    // Unknown contribution name.
    $zeile(['Adressnummer' => 1024, 'Vorname' => 'Sven', 'Nachname' => 'Sondertarif', 'Geschlecht' => 'm', 'GebDatum' => '12.12.1969',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 16, 'Abteilungsname (alle aktuell)(1)' => 'Kegeln',
        'Beitragsname (alle aktuell)(1)' => 'Sondertarif']),
    // Family 2: payer, adult and child.
    $zeile(['Adressnummer' => 1026, 'Vorname' => 'Gerda', 'Nachname' => 'Gemeinsam', 'Geschlecht' => 'w', 'GebDatum' => '13.03.1979',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 9, 'Abteilungsname (alle aktuell)(1)' => 'Fußball',
        'Beitragsname (alle aktuell)(1)' => 'Familienkarte', 'MitNotizen' => 'Fußball-Aktiv']),
    $zeile(['Adressnummer' => 1027, 'Vorname' => 'Gustav', 'Nachname' => 'Gemeinsam', 'Geschlecht' => 'm', 'GebDatum' => '14.04.1977',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 34, 'Abteilungsname (alle aktuell)(1)' => 'Turnen',
        'Beitragsname (alle aktuell)(1)' => 'Beitragsfrei FK', 'MitNotizen' => 'Gymnastik']),
    $zeile(['Adressnummer' => 1028, 'Vorname' => 'Greta', 'Nachname' => 'Gemeinsam', 'Geschlecht' => 'w', 'GebDatum' => '01.01.2018',
        'Konto1Iban' => 'DE89 3704 0044 0532 0130 00', 'Abteilungsnummer (alle aktuell)(1)' => 34, 'Abteilungsname (alle aktuell)(1)' => 'Turnen',
        'Beitragsname (alle aktuell)(1)' => 'Beitragsfrei FK']),
    // Lkz empty, country written out (as in the real export) or something unexpected.
    $zeile(['Adressnummer' => 1029, 'Vorname' => 'Lena', 'Nachname' => 'Landlos', 'Geschlecht' => 'w', 'GebDatum' => '02.02.1992',
        'Lkz' => null, 'Land' => 'Deutschland', 'Plz' => 4109, 'Ort' => 'Leipzighausen', 'Konto1Iban' => 'DE89 3704 0044 0532 0130 00',
        'Abteilungsnummer (alle aktuell)(1)' => 1, 'Abteilungsname (alle aktuell)(1)' => 'Badminton', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene']),
    $zeile(['Adressnummer' => 1030, 'Vorname' => 'Ulf', 'Nachname' => 'Unterwegs', 'Geschlecht' => 'm', 'GebDatum' => '03.03.1973',
        'Lkz' => null, 'Land' => 'Atlantis', 'Konto1Iban' => 'DE89 3704 0044 0532 0130 00',
        'Abteilungsnummer (alle aktuell)(1)' => 1, 'Abteilungsname (alle aktuell)(1)' => 'Badminton', 'Beitragsname (alle aktuell)(1)' => 'Erwachsene']),
];

$familien = [
    ['FamID', 'FamName', 'AdrNr', 'Name', 'Straße', 'GebDat', 'Alter'],
    [501, 'Familie Familie', 1007, 'Familie, Paula', 'Musterweg 1', '08.08.1982', 44],
    [501, 'Familie Familie', 1008, 'Familie, Peter', 'Musterweg 1', '17.04.1980', 46],
    [501, 'Familie Familie', 1009, 'Familie, Pia', 'Musterweg 1', '23.12.2012', 13],
    [502, 'Familie Gemeinsam', 1026, 'Gemeinsam, Gerda', 'Musterweg 1', '13.03.1979', 47],
    [502, 'Familie Gemeinsam', 1027, 'Gemeinsam, Gustav', 'Musterweg 1', '14.04.1977', 49],
    [502, 'Familie Gemeinsam', 1028, 'Gemeinsam, Greta', 'Musterweg 1', '01.01.2018', 8],
    // Points at somebody who is not in the member file.
    [503, 'Familie Unbekannt', 9999, 'Unbekannt, Uli', 'Musterweg 1', '01.01.1980', 46],
];

return ['mitglieder' => $mitglieder, 'familien' => $familien];
