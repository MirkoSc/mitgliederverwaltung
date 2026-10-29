<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Herkunft;
use App\Domain\Mitglied;
use App\Domain\Zahlart;
use App\Support\Datum;
use App\Support\Iban;

/**
 * Reads import.csv (docs/spec/10-import.md section 2) and checks it line by
 * line: UTF-8, ";" as separator, first line the column names, dates as
 * DD.MM.YYYY, an empty cell is "no value". Reads everything and reports all
 * problems at once, so the preview can list them; nothing is stored here.
 *
 * Only the member number and the surname are required. A column may be
 * missing altogether (then it is empty in every line); a column the format
 * does not know is ignored and named. Hints about the content (age, fee,
 * family size ...) are not this class's business - they come from
 * App\Service\Mitglied\Hinweise.
 */
final class ImportLeser
{
    /** The columns of the format, in the order of the table in 10 section 2. */
    public const array SPALTEN = [
        'mitgliedsnummer', 'anrede', 'titel', 'vorname', 'nachname', 'namenszusatz', 'geschlecht',
        'geburtsdatum', 'eintrittsdatum', 'austrittsdatum', 'austrittsgrund',
        'strasse', 'plz', 'ort', 'land', 'telefon', 'handy', 'email',
        'aktiv', 'abteilungen', 'beitragsart', 'zahlart', 'iban', 'bic', 'kontoinhaber',
        'mandatsreferenz', 'mandatsdatum', 'familie', 'familie_name', 'familie_rolle',
        'funktionen', 'lizenznummer', 'bemerkung',
    ];

    /** Longest value per text column - what the database column holds. */
    private const array MAXIMUM = [
        'anrede' => 32, 'titel' => 64, 'vorname' => 100, 'nachname' => 100, 'namenszusatz' => 64,
        'austrittsgrund' => 255, 'strasse' => 150, 'plz' => 10, 'ort' => 100, 'land' => 3,
        'telefon' => 50, 'handy' => 50, 'email' => 254, 'iban' => 34, 'bic' => 11,
        'kontoinhaber' => 140, 'mandatsreferenz' => 35, 'lizenznummer' => 64,
        'familie' => 64, 'familie_name' => 150,
    ];

    /** The largest member number the column (INT UNSIGNED) holds. */
    private const int MAX_NUMMER = 4294967295;

    public static function lesen(string $csv, ImportStammdaten $stammdaten): ImportDatei
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            return self::dateiFehler('Die Datei ist keine Textdatei in UTF-8. Bitte import.csv mit dem Umwandlungsskript erzeugen.');
        }

        $zeilen = self::zeilen($csv);
        if ($zeilen === []) {
            return self::dateiFehler('Die Datei ist leer.');
        }

        $kopf = array_map(static fn(string $name): string => mb_strtolower(trim($name)), array_shift($zeilen)['felder']);
        $spalten = array_flip($kopf);
        if (count($spalten) !== count($kopf)) {
            return self::dateiFehler('In der ersten Zeile kommt eine Spalte mehrfach vor.');
        }
        $fehlt = array_values(array_diff(['mitgliedsnummer', 'nachname'], $kopf));
        if ($fehlt !== []) {
            return self::dateiFehler(sprintf(
                'In der ersten Zeile fehlt die Spalte „%s“. Ist das die richtige Datei (import.csv, Trennzeichen „;“)?',
                implode('“, „', $fehlt),
            ));
        }
        $unbekannt = array_values(array_diff($kopf, self::SPALTEN, ['']));

        $eintraege = [];
        $fehler = [];
        $nummern = [];
        $familien = [];
        $familienNamen = [];
        foreach ($zeilen as ['zeile' => $zeile, 'felder' => $felder]) {
            $ergebnis = self::zeile($zeile, $felder, $kopf, $stammdaten);
            $nummer = $ergebnis['nummer'];
            if ($nummer !== null) {
                $nummern[$nummer][] = $zeile;
            }
            foreach ($ergebnis['fehler'] as $text) {
                $fehler[] = new ImportFehler($zeile, $nummer, $text);
            }
            if ($ergebnis['eintrag'] === null) {
                continue;
            }
            $eintrag = $ergebnis['eintrag'];
            $eintraege[] = $eintrag;
            if ($eintrag->familie !== null) {
                $familien[$eintrag->familie] ??= 'Familie ' . $eintrag->mitglied->nachname;
                if ($ergebnis['familienname'] !== '' && !isset($familienNamen[$eintrag->familie])) {
                    $familienNamen[$eintrag->familie] = $ergebnis['familienname'];
                }
            }
        }

        // A number twice makes every line carrying it wrong: which of them
        // is the real one is for the person to decide.
        $doppelt = array_filter($nummern, static fn(array $zeilenNummern): bool => count($zeilenNummern) > 1);
        foreach ($doppelt as $nummer => $zeilenNummern) {
            foreach ($zeilenNummern as $zeile) {
                $fehler[] = new ImportFehler($zeile, $nummer, sprintf(
                    'Die Mitgliedsnummer kommt mehrfach vor (Zeilen %s).',
                    implode(', ', $zeilenNummern),
                ));
            }
        }
        if ($doppelt !== []) {
            // Report in file order and keep the duplicates out of the entries.
            $eintraege = array_values(array_filter(
                $eintraege,
                static fn(ImportEintrag $e): bool => !isset($doppelt[$e->mitglied->mitgliedsnummer]),
            ));
            usort($fehler, static fn(ImportFehler $a, ImportFehler $b): int => $a->zeile <=> $b->zeile);
        }

        if ($eintraege === [] && $fehler === []) {
            return self::dateiFehler('Die Datei enthält keine Mitglieder, nur die Kopfzeile.');
        }

        return new ImportDatei($eintraege, $fehler, array_replace($familien, $familienNamen), $unbekannt, array_values(array_intersect(self::SPALTEN, $kopf)));
    }

    /**
     * The lines of the file with their line numbers. A cell may hold line
     * breaks inside quotes (Bemerkung), so the line number is that of the
     * line the record starts on.
     *
     * @return list<array{zeile: int, felder: list<string>}>
     */
    private static function zeilen(string $csv): array
    {
        $strom = fopen('php://memory', 'r+b');
        if ($strom === false) {
            throw new \RuntimeException('No memory for reading the file.');
        }
        fwrite($strom, $csv);
        rewind($strom);

        $zeilen = [];
        $zeile = 1;
        $vorher = 0;
        while (($felder = fgetcsv($strom, null, ';', '"', '')) !== false) {
            $ende = (int) ftell($strom);
            $start = $zeile;
            // The record may span several lines (a quoted cell with breaks).
            $zeile += max(1, substr_count(substr($csv, $vorher, $ende - $vorher), "\n"));
            $vorher = $ende;
            if ($felder === [null]) {
                continue; // an empty line
            }
            $zeilen[] = ['zeile' => $start, 'felder' => array_map(static fn(?string $feld): string => (string) $feld, $felder)];
        }
        fclose($strom);

        return $zeilen;
    }

    /**
     * @param list<string> $felder
     * @param list<string> $kopf
     * @return array{nummer: ?int, eintrag: ?ImportEintrag, fehler: list<string>, familienname: string}
     */
    private static function zeile(int $zeile, array $felder, array $kopf, ImportStammdaten $stammdaten): array
    {
        $fehler = [];
        if (count($felder) !== count($kopf)) {
            return [
                'nummer' => null,
                'eintrag' => null,
                'fehler' => [sprintf('Die Zeile hat %d statt %d Spalten (Anführungszeichen oder „;“ im Text?).', count($felder), count($kopf))],
                'familienname' => '',
            ];
        }
        $wert = array_combine($kopf, array_map(trim(...), $felder));
        $text = static fn(string $spalte): string => $wert[$spalte] ?? '';

        $nummer = null;
        $nummerText = $text('mitgliedsnummer');
        if ($nummerText === '') {
            $fehler[] = 'Die Mitgliedsnummer fehlt.';
        } elseif (!ctype_digit($nummerText) || (int) $nummerText < 1 || strlen($nummerText) > 10 || (int) $nummerText > self::MAX_NUMMER) {
            $fehler[] = 'Die Mitgliedsnummer ist keine ganze Zahl über 0.';
        } else {
            $nummer = (int) $nummerText;
        }
        if ($text('nachname') === '') {
            $fehler[] = 'Der Nachname fehlt.';
        }

        foreach (self::MAXIMUM as $spalte => $laenge) {
            $inhalt = $spalte === 'iban' ? Iban::normalisieren($text($spalte)) : $text($spalte);
            if (mb_strlen($inhalt) > $laenge) {
                $fehler[] = sprintf('Die Spalte „%s“ ist zu lang (höchstens %d Zeichen).', $spalte, $laenge);
            }
        }

        $geschlecht = null;
        if ($text('geschlecht') !== '') {
            $geschlecht = Geschlecht::tryFrom(mb_strtolower($text('geschlecht')));
            if ($geschlecht === null) {
                $fehler[] = 'Das Geschlecht muss m, w oder d sein.';
            }
        }

        $datum = static function (string $spalte) use ($text, &$fehler): ?\DateTimeImmutable {
            if ($text($spalte) === '') {
                return null;
            }
            $datum = Datum::ausEingabe($text($spalte));
            if ($datum === null) {
                $fehler[] = sprintf('Die Spalte „%s“ enthält kein gültiges Datum (erwartet TT.MM.JJJJ).', $spalte);
            }

            return $datum;
        };
        $geburtsdatum = $datum('geburtsdatum');
        $eintrittsdatum = $datum('eintrittsdatum');
        $austrittsdatum = $datum('austrittsdatum');
        $mandatsdatum = $datum('mandatsdatum');

        $aktiv = true;
        if ($text('aktiv') !== '') {
            if (!in_array($text('aktiv'), ['0', '1'], true)) {
                $fehler[] = 'Die Spalte „aktiv“ muss 1 oder 0 enthalten.';
            } else {
                $aktiv = $text('aktiv') === '1';
            }
        }

        $abteilungIds = [];
        foreach (self::liste($text('abteilungen')) as $blsv) {
            $abteilung = $stammdaten->abteilung($blsv);
            if ($abteilung === null || $abteilung->id === null) {
                $fehler[] = sprintf('Die Abteilung mit der BLSV-Nummer „%s“ gibt es nicht. Sie kann unter Einstellungen → Abteilungen angelegt werden.', $blsv);
            } else {
                $abteilungIds[] = $abteilung->id;
            }
        }

        $beitragsartId = null;
        if ($text('beitragsart') !== '') {
            $art = $stammdaten->beitragsart($text('beitragsart'));
            if ($art === null || $art->id === null) {
                $fehler[] = sprintf('Die Beitragsart „%s“ gibt es nicht. Sie kann unter Einstellungen → Beitragsarten angelegt werden.', $text('beitragsart'));
            } else {
                $beitragsartId = $art->id;
            }
        }

        $zahlart = Zahlart::Lastschrift;
        if ($text('zahlart') !== '') {
            $gelesen = Zahlart::tryFrom(mb_strtolower($text('zahlart')));
            if ($gelesen === null) {
                $fehler[] = 'Die Zahlart muss lastschrift oder selbst sein.';
            } else {
                $zahlart = $gelesen;
            }
        }

        $funktionIds = [];
        foreach (self::liste($text('funktionen')) as $name) {
            $funktion = $stammdaten->funktion($name);
            if ($funktion === null || $funktion->id === null) {
                $fehler[] = sprintf('Die Funktion „%s“ gibt es nicht. Sie kann unter Einstellungen → Funktionen angelegt werden.', $name);
            } else {
                $funktionIds[] = $funktion->id;
            }
        }

        $familie = $text('familie') === '' ? null : $text('familie');
        $rolle = null;
        if ($familie !== null) {
            $rolle = FamilienRolle::tryFrom(mb_strtolower($text('familie_rolle')));
            if ($rolle === null) {
                $fehler[] = 'Zu einer Familie gehört die Rolle zahler, elternteil oder kind.';
            }
        } elseif ($text('familie_rolle') !== '') {
            $fehler[] = 'Es ist eine Familienrolle angegeben, aber keine Familie.';
        }

        if ($fehler !== [] || $nummer === null) {
            return ['nummer' => $nummer, 'eintrag' => null, 'fehler' => $fehler, 'familienname' => ''];
        }

        $mitglied = new Mitglied(
            mitgliedsnummer: $nummer,
            nachname: $text('nachname'),
            vorname: $text('vorname'),
            anrede: $text('anrede'),
            titel: $text('titel'),
            namenszusatz: $text('namenszusatz'),
            geschlecht: $geschlecht,
            geburtsdatum: $geburtsdatum,
            strasse: $text('strasse'),
            plz: $text('plz'),
            ort: $text('ort'),
            // An empty country is the default country, as for a new member (07 section 1).
            land: $text('land') === '' ? Mitglied::LAND_DEFAULT : $text('land'),
            telefon: $text('telefon'),
            handy: $text('handy'),
            email: $text('email'),
            eintrittsdatum: $eintrittsdatum,
            austrittsdatum: $austrittsdatum,
            austrittsgrund: $text('austrittsgrund'),
            aktiv: $aktiv,
            abteilungIds: array_values(array_unique($abteilungIds)),
            beitragsartId: $beitragsartId,
            zahlart: $zahlart,
            iban: Iban::normalisieren($text('iban')),
            bic: $text('bic'),
            kontoinhaber: $text('kontoinhaber'),
            mandatsreferenz: $text('mandatsreferenz'),
            mandatsdatum: $mandatsdatum,
            funktionIds: array_values(array_unique($funktionIds)),
            lizenznummer: $text('lizenznummer'),
            bemerkung: $text('bemerkung'),
            herkunft: Herkunft::Import,
        );

        return [
            'nummer' => $nummer,
            'eintrag' => new ImportEintrag($zeile, $familie === null ? $mitglied : $mitglied->mit(['familieRolle' => $rolle]), $familie),
            'fehler' => [],
            'familienname' => $text('familie_name'),
        ];
    }

    /**
     * "09, 34" => ["09", "34"]; empty parts are dropped.
     *
     * @return list<string>
     */
    private static function liste(string $text): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $text)),
            static fn(string $teil): bool => $teil !== '',
        ));
    }

    private static function dateiFehler(string $text): ImportDatei
    {
        return new ImportDatei([], [new ImportFehler(0, null, $text)], []);
    }
}
