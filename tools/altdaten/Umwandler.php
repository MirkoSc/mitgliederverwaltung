<?php

declare(strict_types=1);

namespace Tools\Altdaten;

/**
 * Old member software (xlsx export) -> import.csv (docs/spec/10-import.md
 * sections 2 and 3, mapping tables in docs/fachlichkeit.md section 8).
 *
 * Works on rows already read from the two files (first row = header), so it
 * is testable without a file. Holds the counters of the current run in a
 * property: one instance converts one pair of files.
 */
final class Umwandler
{
    /** Columns of import.csv in file order (spec 10 section 2). */
    public const array SPALTEN = [
        'mitgliedsnummer', 'anrede', 'titel', 'vorname', 'nachname', 'namenszusatz', 'geschlecht',
        'geburtsdatum', 'eintrittsdatum', 'austrittsdatum', 'austrittsgrund',
        'strasse', 'plz', 'ort', 'land', 'telefon', 'handy', 'email',
        'aktiv', 'abteilungen', 'beitragsart', 'zahlart', 'iban', 'bic', 'kontoinhaber',
        'mandatsreferenz', 'mandatsdatum', 'familie', 'familie_name', 'familie_rolle',
        'funktionen', 'lizenznummer', 'bemerkung',
    ];

    /**
     * Columns of the old member file: key => [display name, aliases...].
     * Names as written in docs/fachlichkeit.md section 8; the matching ignores
     * case, spaces and punctuation ("E-Mail" = "EMail").
     */
    private const array QUELLSPALTEN = [
        'nummer'        => ['Adressnummer', 'adressnummer'],
        'anrede'        => ['Anrede', 'anrede'],
        'titel'         => ['Titel', 'titel'],
        'vorname'       => ['Vorname', 'vorname'],
        'nachname'      => ['Nachname', 'nachname'],
        'geschlecht'    => ['Geschlecht', 'geschlecht'],
        'geburt'        => ['GebDatum', 'gebdatum'],
        'strasse'       => ['Strasse', 'strasse', 'straße'],
        'plz'           => ['Plz', 'plz'],
        'ort'           => ['Ort', 'ort'],
        'land'          => ['Land', 'land'],
        'lkz'           => ['Lkz', 'lkz'],
        'telefon'       => ['Telefon', 'telefon'],
        'handy'         => ['Handy', 'handy'],
        'email'         => ['EMail', 'email'],
        'iban'          => ['Konto1Iban', 'konto1iban'],
        'bic'           => ['Konto1Bic', 'konto1bic'],
        'zahlernummer'  => ['ZahlerAdressNummer', 'zahleradressnummer'],
        'zahlervorname' => ['ZahlerVorname', 'zahlervorname'],
        'zahlername'    => ['ZahlerNachname', 'zahlernachname'],
        'zahleriban'    => ['MitZahlerIban', 'mitzahleriban'],
        'eintritt'      => ['EintrittsDatum', 'eintrittsdatum'],
        'austritt'      => ['AustrittsDatum', 'austrittsdatum'],
        'grund'         => ['Austrittsgrund', 'austrittsgrund'],
        'zahlart'       => ['Zahlart', 'zahlart'],
        'adrnotizen'    => ['AdrNotizen', 'adrnotizen'],
        'mitnotizen'    => ['MitNotizen', 'mitnotizen'],
    ];

    /** Columns that must exist; without them the file is not the expected one. */
    private const array PFLICHT = ['nummer', 'nachname'];

    /** Columns of the family file (only FamID and AdrNr matter, F4). */
    private const array FAMILIENSPALTEN = [
        'id'     => ['FamID', 'famid'],
        'name'   => ['FamName', 'famname'],
        'nummer' => ['AdrNr', 'adrnr'],
    ];

    /** Old departments by normalised name (spec 10 section 3: "Turnen" is BLSV 34). */
    private const array ABTEILUNGSNAMEN = [
        'badminton' => '01',
        'fußball'   => '09',
        'fussball'  => '09',
        'kegeln'    => '16',
        'turnen'    => '34',
        'gymnastik' => '34',
    ];

    private const array BEKANNTE_BLSV = ['01', '09', '16', '34'];

    /** Old base contributions (normalised name => kind). The surcharge "aktiv" is handled apart. */
    private const array GRUNDBEITRAEGE = [
        'erwachsene'     => 'erwachsene',
        'kinder'         => 'kinder',
        'jugendliche'    => 'jugendliche',
        'rentner'        => 'rentner',
        'familienkarte'  => 'familienkarte',
        'beitragsfreifk' => 'beitragsfrei_fk',
        'mindestbeitrag' => 'mindestbeitrag',
        'beitragsfrei'   => 'beitragsfrei',
    ];

    /** Which base contribution wins if a member has several (highest amount first). */
    private const array RANGFOLGE = [
        'familienkarte', 'erwachsene', 'kinder', 'jugendliche', 'rentner',
        'mindestbeitrag', 'beitragsfrei_fk', 'beitragsfrei',
    ];

    private const string PLATZHALTER = '01.01.1901';

    private Zaehler $z;

    public function __construct(private readonly \DateTimeImmutable $stichtag)
    {
        $this->z = new Zaehler();
    }

    /**
     * @param list<list<string>> $mitglieder rows of the member file, header first
     * @param list<list<string>> $familien   rows of the family file, header first
     */
    public function umwandeln(array $mitglieder, array $familien): Ergebnis
    {
        $this->z = new Zaehler();

        $kopf = array_shift($mitglieder) ?? [];
        $spalten = self::spaltenSuchen($kopf, self::QUELLSPALTEN);
        $fehlend = [];
        foreach (self::QUELLSPALTEN as $schluessel => $angaben) {
            if (isset($spalten[$schluessel])) {
                continue;
            }
            if (in_array($schluessel, self::PFLICHT, true)) {
                throw new \RuntimeException('In der Mitgliederdatei fehlt die Spalte "' . $angaben[0] . '" - ist das die richtige Datei?');
            }
            if ($schluessel !== 'lkz' && $schluessel !== 'land') {
                $fehlend[] = $angaben[0];
            }
        }
        if (!isset($spalten['land']) && !isset($spalten['lkz'])) {
            $fehlend[] = 'Land/Lkz';
        }
        $abteilungsnummern = self::platzspalten($kopf, 'abteilungsnummer');
        $abteilungsnamen = self::platzspalten($kopf, 'abteilungsname');
        $beitragsspalten = self::platzspalten($kopf, 'beitragsname');
        if ($abteilungsnummern === [] && $abteilungsnamen === []) {
            throw new \RuntimeException('In der Mitgliederdatei fehlen die Abteilungsspalten "(alle aktuell)(1..3)".');
        }
        if ($beitragsspalten === []) {
            throw new \RuntimeException('In der Mitgliederdatei fehlen die Beitragsspalten "Beitragsname (alle aktuell)(1..3)".');
        }
        $lizenzspalte = self::lizenzspalte($kopf);
        if ($lizenzspalte === null) {
            $fehlend[] = 'Uel* (Lizenznummer)';
        }

        $familienzuordnung = $this->familienLesen($familien);

        $zeilen = array_values(array_filter($mitglieder, static fn(array $zeile): bool => implode('', $zeile) !== ''));

        // All rows by Adressnummer: a payer without membership is only needed
        // as name and account of the members he or she pays for.
        $nachNummer = [];
        foreach ($zeilen as $zeile) {
            $nummer = self::feld($zeile, $spalten, 'nummer');
            if (preg_match('/^\d+$/D', $nummer) === 1) {
                $nachNummer[(int) $nummer] ??= $zeile;
            }
        }

        $ausgabe = [];
        $gesehen = [];
        $familienrollen = [];
        $familienmitglieder = [];
        foreach ($zeilen as $zeile) {
            $this->z->plus('gelesen');
            $nummer = self::feld($zeile, $spalten, 'nummer');
            if (preg_match('/^\d+$/D', $nummer) !== 1) {
                $this->z->plus('ohne_nummer');
                continue;
            }
            $nummer = (string) (int) $nummer;

            $abteilungen = $this->abteilungen($zeile, $abteilungsnummern, $abteilungsnamen);
            if ($abteilungen === []) {
                $this->z->plus('ohne_mitgliedschaft');
                continue;
            }
            if (isset($gesehen[$nummer])) {
                $this->z->plus('doppelte_nummer');
            }
            $gesehen[$nummer] = true;

            $notiz = self::notizen(self::feld($zeile, $spalten, 'adrnotizen'), self::feld($zeile, $spalten, 'mitnotizen'));
            $beitrag = $this->beitrag($zeile, $beitragsspalten, $notiz);
            $geburt = $this->datum(self::feld($zeile, $spalten, 'geburt'));
            $zahlung = $this->zahlung($zeile, $spalten, $nummer, $nachNummer);

            $familie = $familienzuordnung[(int) $nummer] ?? null;
            $rolle = '';
            if ($familie !== null) {
                if ($beitrag['grund'] === 'familienkarte') {
                    $rolle = 'zahler';
                } elseif ($geburt !== '' && $this->alter($geburt) < 18) {
                    $rolle = 'kind';
                } else {
                    $rolle = 'elternteil';
                    if ($geburt === '') {
                        $this->z->plus('rolle_ohne_geburtsdatum');
                    }
                }
                $this->z->plus('rolle_' . $rolle);
                $familienrollen[$familie['id']][] = $rolle;
                $familienmitglieder[(int) $nummer] = true;
            } elseif ($beitrag['grund'] === 'familienkarte') {
                $this->z->plus('fk_ohne_familie');
            } elseif ($beitrag['grund'] === 'beitragsfrei_fk') {
                $this->z->plus('fk_frei_ohne_familie');
            }

            $eintritt = $this->datum(self::feld($zeile, $spalten, 'eintritt'));
            if ($eintritt === '') {
                $this->z->plus('eintritt_unbekannt');
            }

            $funktionen = '';
            $lizenz = '';
            if ($lizenzspalte !== null) {
                $wert = trim($zeile[$lizenzspalte] ?? '');
                if ($wert !== '' && $wert !== '0') {
                    $funktionen = 'Übungsleiter';
                    $lizenz = $wert;
                    $this->z->plus('uebungsleiter');
                }
            }

            $this->z->plus('beitragsart', $beitrag['art']);
            $this->z->plus('kombination', $beitrag['kombination']);
            $this->z->plus('aktiv', $beitrag['aktiv']);
            foreach ($abteilungen as $blsv) {
                $this->z->plus('abteilung', $blsv);
            }
            if (count($abteilungen) > 1) {
                $this->z->plus('mehrere_abteilungen');
            }

            $land = self::feld($zeile, $spalten, 'lkz');
            if ($land === '') {
                $land = self::feld($zeile, $spalten, 'land');
            }
            $plz = self::feld($zeile, $spalten, 'plz');
            if (preg_match('/^\d{4}$/D', $plz) === 1 && in_array(strtoupper($land), ['', 'D', 'DE', 'DEUTSCHLAND'], true)) {
                $plz = '0' . $plz; // a number cell lost its leading zero
            }

            $ausgabe[] = [
                'mitgliedsnummer' => $nummer,
                'anrede'          => self::feld($zeile, $spalten, 'anrede'),
                'titel'           => self::feld($zeile, $spalten, 'titel'),
                'vorname'         => self::feld($zeile, $spalten, 'vorname'),
                'nachname'        => self::feld($zeile, $spalten, 'nachname'),
                'namenszusatz'    => '',
                'geschlecht'      => self::geschlecht(self::feld($zeile, $spalten, 'geschlecht')),
                'geburtsdatum'    => $geburt,
                'eintrittsdatum'  => $eintritt,
                'austrittsdatum'  => $this->datum(self::feld($zeile, $spalten, 'austritt')),
                'austrittsgrund'  => self::feld($zeile, $spalten, 'grund'),
                'strasse'         => self::feld($zeile, $spalten, 'strasse'),
                'plz'             => $plz,
                'ort'             => self::feld($zeile, $spalten, 'ort'),
                'land'            => $land,
                'telefon'         => self::feld($zeile, $spalten, 'telefon'),
                'handy'           => self::feld($zeile, $spalten, 'handy'),
                'email'           => self::feld($zeile, $spalten, 'email'),
                'aktiv'           => $beitrag['aktiv'],
                'abteilungen'     => implode(',', $abteilungen),
                'beitragsart'     => $beitrag['art'],
                'zahlart'         => $zahlung['zahlart'],
                'iban'            => $zahlung['iban'],
                'bic'             => $zahlung['bic'],
                'kontoinhaber'    => $zahlung['kontoinhaber'],
                'mandatsreferenz' => '',
                'mandatsdatum'    => '',
                'familie'         => $familie['id'] ?? '',
                'familie_name'    => $familie['name'] ?? '',
                'familie_rolle'   => $rolle,
                'funktionen'      => $funktionen,
                'lizenznummer'    => $lizenz,
                'bemerkung'       => $notiz,
            ];
        }

        foreach ($familienrollen as $rollen) {
            $this->z->plus('familie');
            $zahler = count(array_filter($rollen, static fn(string $rolle): bool => $rolle === 'zahler'));
            if ($zahler === 0) {
                $this->z->plus('familien_ohne_zahler');
            } elseif ($zahler > 1) {
                $this->z->plus('familien_mehrere_zahler');
            }
        }
        foreach (array_keys($familienzuordnung) as $nummer) {
            if (!isset($familienmitglieder[$nummer])) {
                $this->z->plus('familie_person_ohne_mitglied');
            }
        }

        return new Ergebnis($ausgabe, $this->z, $fehlend);
    }

    /**
     * AdrNr => [id, name] from the family file. A person in two families
     * keeps the first one.
     *
     * @param list<list<string>> $familien
     * @return array<int, array{id: string, name: string}>
     */
    private function familienLesen(array $familien): array
    {
        $kopf = array_shift($familien) ?? [];
        $spalten = self::spaltenSuchen($kopf, self::FAMILIENSPALTEN);
        foreach (['id', 'nummer'] as $pflicht) {
            if (!isset($spalten[$pflicht])) {
                throw new \RuntimeException('In der Familiendatei fehlt die Spalte "' . self::FAMILIENSPALTEN[$pflicht][0] . '" - ist das die richtige Datei?');
            }
        }

        $namen = [];
        foreach ($familien as $zeile) {
            $id = self::feld($zeile, $spalten, 'id');
            $name = self::feld($zeile, $spalten, 'name');
            if ($id !== '' && $name !== '' && !isset($namen[$id])) {
                $namen[$id] = $name;
            }
        }

        $zuordnung = [];
        foreach ($familien as $zeile) {
            $id = self::feld($zeile, $spalten, 'id');
            $nummer = self::feld($zeile, $spalten, 'nummer');
            if ($id === '' || preg_match('/^\d+$/D', $nummer) !== 1) {
                continue;
            }
            if (isset($zuordnung[(int) $nummer])) {
                $this->z->plus('familie_doppelt');
                continue;
            }
            $zuordnung[(int) $nummer] = ['id' => $id, 'name' => $namen[$id] ?? ''];
        }

        return $zuordnung;
    }

    /**
     * Distinct BLSV numbers of the (up to three) current department slots.
     *
     * @param list<string> $zeile
     * @param array<int, int> $nummernspalten slot => column
     * @param array<int, int> $namensspalten  slot => column
     * @return list<string>
     */
    private function abteilungen(array $zeile, array $nummernspalten, array $namensspalten): array
    {
        $ergebnis = [];
        foreach ([1, 2, 3] as $platz) {
            $nummer = isset($nummernspalten[$platz]) ? trim($zeile[$nummernspalten[$platz]] ?? '') : '';
            $name = isset($namensspalten[$platz]) ? trim($zeile[$namensspalten[$platz]] ?? '') : '';
            if ($nummer === '' && $name === '') {
                continue;
            }
            $blsv = self::ABTEILUNGSNAMEN[self::normal($name)] ?? null;
            if ($blsv === null && preg_match('/^\d+$/D', $nummer) === 1) {
                $blsv = str_pad((string) (int) $nummer, 2, '0', STR_PAD_LEFT);
            }
            if ($blsv === null) {
                $this->z->plus('unbekannte_abteilung');
                continue;
            }
            if (!in_array($blsv, self::BEKANNTE_BLSV, true)) {
                $this->z->plus('abteilung_ohne_blsv');
            }
            if (!in_array($blsv, $ergebnis, true)) {
                $ergebnis[] = $blsv;
            }
        }

        return $ergebnis;
    }

    /**
     * Old base contribution(s) + surcharge "aktiv" -> new contribution type
     * and the active flag (docs/fachlichkeit.md section 8, table "alt -> neu").
     *
     * @param list<string> $zeile
     * @param array<int, int> $beitragsspalten slot => column
     * @return array{grund: string, art: string, aktiv: string, kombination: string}
     */
    private function beitrag(array $zeile, array $beitragsspalten, string $notiz): array
    {
        $gefunden = [];
        $zuschlag = false;
        foreach ($beitragsspalten as $spalte) {
            $name = trim($zeile[$spalte] ?? '');
            if ($name === '') {
                continue;
            }
            $schluessel = self::normal($name);
            if ($schluessel === 'aktiv') {
                $zuschlag = true;
            } elseif (isset(self::GRUNDBEITRAEGE[$schluessel])) {
                $gefunden[self::GRUNDBEITRAEGE[$schluessel]] = true;
            } else {
                $this->z->plus('unbekannter_beitragsname', $name);
            }
        }

        $grund = '';
        foreach (self::RANGFOLGE as $art) {
            if (isset($gefunden[$art])) {
                $grund = $art;
                break;
            }
        }
        if (count($gefunden) > 1) {
            $this->z->plus('mehrere_grundbeitraege');
        }
        // Active by note only where the word starts ("Fußball-Aktiv", not "inaktiv").
        $ausNotiz = preg_match('/(?<!\p{L})aktiv/iu', $notiz) === 1;

        [$art, $aktiv, $kombination] = match ($grund) {
            'erwachsene'      => $zuschlag ? ['Aktiv', true, 'Erwachsene + aktiv'] : ['Passiv', false, 'Erwachsene'],
            'kinder'          => ['Jugend/Schüler/Studenten/Azubis', true, 'Kinder'],
            'jugendliche'     => ['Jugend/Schüler/Studenten/Azubis', true, 'Jugendliche'],
            'rentner'         => $zuschlag ? ['Rentner aktiv', true, 'Rentner + aktiv'] : ['Rentner passiv', false, 'Rentner'],
            'familienkarte'   => ['Familie', $zuschlag || $ausNotiz, 'Familienkarte'],
            'beitragsfrei_fk' => ['', $zuschlag || $ausNotiz, 'Beitragsfrei FK'],
            'mindestbeitrag'  => ['Mindestbeitrag', $zuschlag, $zuschlag ? 'Mindestbeitrag + aktiv' : 'Mindestbeitrag'],
            'beitragsfrei'    => ['Beitragsfrei', true, 'Beitragsfrei'],
            default           => ['', $zuschlag || $ausNotiz, '(nicht zuordenbar)'],
        };
        if ($grund === '') {
            $this->z->plus('beitrag_nicht_zuordenbar');
        }

        return ['grund' => $grund, 'art' => $art, 'aktiv' => $aktiv ? '1' : '0', 'kombination' => $kombination];
    }

    /**
     * Payment type, account and - only if somebody else pays - the account
     * holder (docs/fachlichkeit.md section 8: "nur wenn Zahler != Mitglied").
     *
     * @param list<string> $zeile
     * @param array<string, int> $spalten
     * @param array<int, list<string>> $nachNummer
     * @return array{zahlart: string, iban: string, bic: string, kontoinhaber: string}
     */
    private function zahlung(array $zeile, array $spalten, string $nummer, array $nachNummer): array
    {
        $art = self::normal(self::feld($zeile, $spalten, 'zahlart'));
        $zahlart = match (true) {
            str_starts_with($art, 'lastschrift') => 'lastschrift',
            str_starts_with($art, 'rechnung') => 'selbst',
            default => '',
        };
        if ($zahlart === '') {
            $this->z->plus('zahlart_unbekannt');
        } else {
            $this->z->plus('zahlart', $zahlart);
        }

        $zahlernummer = self::feld($zeile, $spalten, 'zahlernummer');
        $hatNummer = preg_match('/^\d+$/D', $zahlernummer) === 1;
        $zahlerzeile = $hatNummer ? ($nachNummer[(int) $zahlernummer] ?? null) : null;
        $zahlername = trim(self::feld($zeile, $spalten, 'zahlervorname') . ' ' . self::feld($zeile, $spalten, 'zahlername'));
        $eigenerName = trim(self::feld($zeile, $spalten, 'vorname') . ' ' . self::feld($zeile, $spalten, 'nachname'));

        $abweichend = $hatNummer
            ? (string) (int) $zahlernummer !== $nummer
            : ($zahlername !== '' && mb_strtolower($zahlername) !== mb_strtolower($eigenerName));

        $iban = self::feld($zeile, $spalten, 'iban');
        $bic = self::feld($zeile, $spalten, 'bic');
        $kontoinhaber = '';
        if ($abweichend) {
            $this->z->plus('abweichender_zahler');
            if ($zahlername === '' && $zahlerzeile !== null) {
                $zahlername = trim(self::feld($zahlerzeile, $spalten, 'vorname') . ' ' . self::feld($zahlerzeile, $spalten, 'nachname'));
            }
            if ($zahlername === '') {
                $this->z->plus('zahler_unbekannt');
            }
            $kontoinhaber = $zahlername;
            $zahleriban = self::feld($zeile, $spalten, 'zahleriban');
            if ($zahleriban !== '') {
                $iban = $zahleriban;
            } elseif ($iban === '' && $zahlerzeile !== null) {
                $iban = self::feld($zahlerzeile, $spalten, 'iban');
                $bic = $bic === '' ? self::feld($zahlerzeile, $spalten, 'bic') : $bic;
            }
        }
        $iban = strtoupper((string) preg_replace('/\s+/u', '', $iban));
        if ($zahlart === 'lastschrift' && $iban === '') {
            $this->z->plus('lastschrift_ohne_iban');
        }

        return ['zahlart' => $zahlart, 'iban' => $iban, 'bic' => $bic, 'kontoinhaber' => $kontoinhaber];
    }

    /** "TT.MM.JJJJ" for what the old software wrote; '' for none, placeholder or unreadable. */
    private function datum(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$/D', $text, $m) === 1) {
            [$tag, $monat, $jahr] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/D', $text, $m) === 1) {
            [$jahr, $monat, $tag] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^\d{3,6}$/D', $text) === 1) {
            // an Excel date cell that arrived as a serial number
            $serie = (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $text . ' days');
            [$tag, $monat, $jahr] = [(int) $serie->format('j'), (int) $serie->format('n'), (int) $serie->format('Y')];
        } else {
            $this->z->plus('datum_kaputt');

            return '';
        }
        if (!checkdate($monat, $tag, $jahr)) {
            $this->z->plus('datum_kaputt');

            return '';
        }
        $ergebnis = sprintf('%02d.%02d.%04d', $tag, $monat, $jahr);
        if ($ergebnis === self::PLATZHALTER) {
            $this->z->plus('platzhalter_datum');

            return '';
        }

        return $ergebnis;
    }

    /** Age in full years at the reference day; $geburt is "TT.MM.JJJJ". */
    private function alter(string $geburt): int
    {
        $tag = \DateTimeImmutable::createFromFormat('!d.m.Y', $geburt);

        return $tag === false ? 0 : $tag->diff($this->stichtag)->y;
    }

    private static function geschlecht(string $text): string
    {
        $erster = mb_strtolower(mb_substr(trim($text), 0, 1));

        return in_array($erster, ['m', 'w', 'd'], true) ? $erster : '';
    }

    /** AdrNotizen and MitNotizen in one text; identical texts only once. */
    private static function notizen(string ...$notizen): string
    {
        $teile = [];
        foreach ($notizen as $notiz) {
            $notiz = trim(str_replace(["\r\n", "\r"], "\n", $notiz));
            if ($notiz !== '' && !in_array($notiz, $teile, true)) {
                $teile[] = $notiz;
            }
        }

        return implode("\n", $teile);
    }

    /**
     * @param list<string> $kopf
     * @param array<string, list<string>> $definition key => [display name, aliases...]
     * @return array<string, int> key => column index
     */
    private static function spaltenSuchen(array $kopf, array $definition): array
    {
        $nachName = [];
        foreach ($kopf as $index => $name) {
            $nachName[self::normal($name)] ??= $index;
        }
        $gefunden = [];
        foreach ($definition as $schluessel => $angaben) {
            foreach (array_slice($angaben, 1) as $alias) {
                if (isset($nachName[self::normal($alias)])) {
                    $gefunden[$schluessel] = $nachName[self::normal($alias)];
                    break;
                }
            }
        }

        return $gefunden;
    }

    /**
     * Columns "<basis> (alle aktuell)(1..3)" by slot. The history group
     * "(alle)(1..3)" never matches: only current entries count.
     *
     * @param list<string> $kopf
     * @return array<int, int> slot => column index
     */
    private static function platzspalten(array $kopf, string $basis): array
    {
        $ergebnis = [];
        foreach ($kopf as $index => $name) {
            if (preg_match('/^' . $basis . 'alleaktuell([1-3])$/D', self::normal($name), $m) === 1) {
                $ergebnis[(int) $m[1]] ??= $index;
            }
        }

        return $ergebnis;
    }

    /**
     * The licence number column among the Uel* columns (15 licensed trainers
     * in the old data): the first whose name says licence, else number.
     *
     * @param list<string> $kopf
     */
    private static function lizenzspalte(array $kopf): ?int
    {
        $uel = [];
        foreach ($kopf as $index => $name) {
            if (str_starts_with(self::normal($name), 'uel')) {
                $uel[$index] = self::normal($name);
            }
        }
        foreach (['lizenz', 'nummer', 'nr'] as $teil) {
            foreach ($uel as $index => $name) {
                if (str_contains($name, $teil)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $zeile
     * @param array<string, int> $spalten
     */
    private static function feld(array $zeile, array $spalten, string $schluessel): string
    {
        return isset($spalten[$schluessel]) ? trim($zeile[$spalten[$schluessel]] ?? '') : '';
    }

    /** Lower case, only letters and digits - the key for header and value comparisons. */
    private static function normal(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    }
}
