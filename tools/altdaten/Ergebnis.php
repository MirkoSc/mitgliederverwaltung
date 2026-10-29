<?php

declare(strict_types=1);

namespace Tools\Altdaten;

/** Result of one conversion: the rows of import.csv plus the counters. */
final readonly class Ergebnis
{
    /**
     * @param list<array<string, string>> $zeilen   keys = Umwandler::SPALTEN
     * @param list<string>                $fehlendeSpalten display names of expected columns that were not found
     */
    public function __construct(
        public array $zeilen,
        public Zaehler $zaehler,
        public array $fehlendeSpalten,
    ) {
    }

    /** The import.csv: UTF-8, ";" separated, header line, no BOM. */
    public function csv(): string
    {
        $puffer = fopen('php://memory', 'w+');
        if ($puffer === false) {
            throw new \RuntimeException('Kein Speicher für die CSV-Ausgabe.');
        }
        fputcsv($puffer, Umwandler::SPALTEN, ';', '"', '', "\n");
        foreach ($this->zeilen as $zeile) {
            fputcsv($puffer, array_map(static fn(string $wert): string => $zeile[$wert] ?? '', Umwandler::SPALTEN), ';', '"', '', "\n");
        }
        rewind($puffer);
        $csv = (string) stream_get_contents($puffer);
        fclose($puffer);

        return $csv;
    }

    /** Short summary for STDERR - counts only, no names. */
    public function zusammenfassung(): string
    {
        $z = $this->zaehler;
        $zeilen = [
            'Umwandlung der Altdaten',
            '',
            'Mitgliederdatei',
            '  Zeilen gelesen:                      ' . $z->summe('gelesen'),
            '  als Mitglied übernommen:             ' . count($this->zeilen),
            '  nicht übernommen, keine Mitgliedschaft: ' . $z->summe('ohne_mitgliedschaft'),
            '  nicht übernommen, ohne Adressnummer: ' . $z->summe('ohne_nummer'),
            '  doppelte Adressnummer (beide drin):  ' . $z->summe('doppelte_nummer'),
            '',
            'Beitragsart (neu)',
        ];
        foreach ($z->gruppe('beitragsart') as $name => $anzahl) {
            $zeilen[] = sprintf('  %-34s %d', $name === '' ? '(keine)' : $name, $anzahl);
        }
        $zeilen[] = '';
        $zeilen[] = 'Alte Beitragskombination';
        foreach ($z->gruppe('kombination') as $name => $anzahl) {
            $zeilen[] = sprintf('  %-34s %d', $name, $anzahl);
        }
        $zeilen[] = '';
        $zeilen[] = 'aktiv / passiv';
        $zeilen[] = '  aktiv:  ' . ($z->gruppe('aktiv')['1'] ?? 0);
        $zeilen[] = '  passiv: ' . ($z->gruppe('aktiv')['0'] ?? 0);
        $zeilen[] = '';
        $zeilen[] = 'Abteilungen (BLSV-Nummer)';
        foreach ($z->gruppe('abteilung') as $nummer => $anzahl) {
            $zeilen[] = sprintf('  %-34s %d', $nummer, $anzahl);
        }
        $zeilen[] = '  Mitglieder in mehreren Abteilungen:  ' . $z->summe('mehrere_abteilungen');
        $zeilen[] = '';
        $zeilen[] = 'Familien';
        $zeilen[] = '  Familien:                            ' . $z->summe('familie');
        $zeilen[] = '  Zahler:                              ' . $z->summe('rolle_zahler');
        $zeilen[] = '  Elternteile (Prüfliste):              ' . $z->summe('rolle_elternteil');
        $zeilen[] = '  Kinder:                              ' . $z->summe('rolle_kind');
        $zeilen[] = '';
        $zeilen[] = 'Zahlung';
        $zeilen[] = '  Lastschrift:                         ' . ($z->gruppe('zahlart')['lastschrift'] ?? 0);
        $zeilen[] = '  selbst (Rechnungszahler):            ' . ($z->gruppe('zahlart')['selbst'] ?? 0);
        $zeilen[] = '  abweichender Zahler (Kontoinhaber):  ' . $z->summe('abweichender_zahler');
        $zeilen[] = '';
        $zeilen[] = 'Sonstiges';
        $zeilen[] = '  Übungsleiter (mit Lizenznummer):     ' . $z->summe('uebungsleiter');
        $zeilen[] = '  Platzhalterdatum 01.01.1901 geleert: ' . $z->summe('platzhalter_datum');
        $zeilen[] = '  Eintrittsdatum unbekannt:            ' . $z->summe('eintritt_unbekannt');

        $hinweise = [
            'beitrag_nicht_zuordenbar'    => 'Mitglieder ohne erkennbare Beitragsart (bitte prüfen)',
            'mehrere_grundbeitraege'      => 'Mitglieder mit mehreren verschiedenen Grundbeiträgen (der höchste zählt)',
            'unbekannte_abteilung'        => 'Abteilungsangaben ohne erkennbare Abteilung (ignoriert)',
            'abteilung_ohne_blsv'         => 'Abteilungen mit unbekannter BLSV-Nummer (Vorschau des Imports meldet sie)',
            'fk_ohne_familie'             => 'Familienkarte ohne Familie',
            'fk_frei_ohne_familie'        => 'Beitragsfrei FK ohne Familie',
            'familie_person_ohne_mitglied' => 'Familienzeilen ohne passendes Mitglied',
            'familie_doppelt'             => 'Personen in mehr als einer Familie (erste zählt)',
            'familien_ohne_zahler'        => 'Familien ohne Zahler',
            'familien_mehrere_zahler'     => 'Familien mit mehreren Zahlern',
            'rolle_ohne_geburtsdatum'     => 'Familienmitglieder ohne Geburtsdatum (als Elternteil geführt)',
            'zahler_unbekannt'            => 'abweichende Zahler ohne auffindbaren Namen',
            'zahlart_unbekannt'           => 'Mitglieder mit unbekannter Zahlart (leer gelassen)',
            'lastschrift_ohne_iban'       => 'Lastschriftzahler ohne IBAN',
            'datum_kaputt'                => 'nicht lesbare Datumsangaben (geleert)',
        ];
        $zeilen[] = '';
        $zeilen[] = 'Zur Prüfung';
        $gefunden = false;
        foreach ($hinweise as $schluessel => $text) {
            if ($z->summe($schluessel) > 0) {
                $zeilen[] = sprintf('  %-56s %d', $text . ':', $z->summe($schluessel));
                $gefunden = true;
            }
        }
        foreach ($z->gruppe('unbekannter_beitragsname') as $name => $anzahl) {
            $zeilen[] = sprintf('  unbekannter Beitragsname "%s": %d', $name, $anzahl);
            $gefunden = true;
        }
        foreach ($this->fehlendeSpalten as $spalte) {
            $zeilen[] = '  Spalte nicht gefunden: ' . $spalte;
            $gefunden = true;
        }
        if (!$gefunden) {
            $zeilen[] = '  nichts Auffälliges';
        }

        return implode("\n", $zeilen) . "\n";
    }
}
