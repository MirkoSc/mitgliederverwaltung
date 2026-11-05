<?php

declare(strict_types=1);

namespace App\Service\Statistik;

use App\Domain\Abteilung;
use App\Domain\Mitglied;
use App\Repository\AbteilungRepository;
use App\Repository\MitgliedRepository;
use App\Service\Mitglied\Hinweise;
use App\Support\Alter;

/**
 * "Statistik – Mitglieder" (docs/spec/09-listen-und-exporte.md section 7):
 * how many members, by age, gender, place, department, family, length of
 * membership - counts only, no names.
 *
 * Counted are the members of the key date (today): former members
 * (istEhemalig) count nowhere - except in "Austritte" per year, which is
 * exactly them. "Eintritte" per year count everyone who joined in that year,
 * whether still a member or not.
 */
final readonly class Mitgliederstatistik
{
    /** Years of "Eintritte und Austritte", the current one included. */
    public const int JAHRE = 10;

    /** Length of membership in whole years: label => [from, to], to null = open. */
    public const array DAUER = [
        'unter 5 Jahre' => [0, 4],
        '5 bis unter 10' => [5, 9],
        '10 bis unter 25' => [10, 24],
        '25 bis unter 40' => [25, 39],
        '40 bis unter 50' => [40, 49],
        '50 und mehr' => [50, null],
    ];

    public const string DAUER_UNBEKANNT = 'Eintritt unbekannt';

    public function __construct(private MitgliedRepository $mitglieder, private AbteilungRepository $abteilungen)
    {
    }

    public static function mit(\PDO $pdo): self
    {
        return new self(new MitgliedRepository($pdo), new AbteilungRepository($pdo));
    }

    public function fuer(\DateTimeImmutable $stichtag): Mitgliederzahlen
    {
        return self::berechnen($stichtag, $this->mitglieder->alle(true), $this->abteilungen->alle());
    }

    /**
     * @param list<Mitglied> $mitglieder all members, former ones too
     * @param list<Abteilung> $abteilungen in display order
     */
    public static function berechnen(\DateTimeImmutable $stichtag, array $mitglieder, array $abteilungen): Mitgliederzahlen
    {
        $aktuelle = array_values(array_filter($mitglieder, static fn(Mitglied $m): bool => !$m->istEhemalig($stichtag)));
        $alter = [];
        foreach ($aktuelle as $mitglied) {
            $alter[(int) $mitglied->id] = $mitglied->alterAm($stichtag);
        }

        return new Mitgliederzahlen($stichtag, self::kennzahlen($aktuelle, $alter), [
            self::altersgruppen($aktuelle, $alter),
            self::lebensphasen($aktuelle, $alter),
            self::abteilungen($aktuelle, $alter, $abteilungen),
            self::wohnorte($aktuelle, 'ort', 'wohnorte', 'Wohnorte', 'Ort', 'Orte'),
            self::wohnorte($aktuelle, 'plz', 'plz', 'Postleitzahlen', 'PLZ', 'Postleitzahlen'),
            self::familien($aktuelle),
            self::dauer($aktuelle, $stichtag),
            self::einUndAustritte($mitglieder, $stichtag),
        ]);
    }

    /**
     * @param list<Mitglied> $aktuelle
     * @param array<int, int|null> $alter
     */
    private static function kennzahlen(array $aktuelle, array $alter): Kennzahlen
    {
        $geschlecht = [0, 0, 0, 0];
        $aktiv = 0;
        foreach ($aktuelle as $mitglied) {
            $geschlecht[Merkmale::geschlecht($mitglied->geschlecht)]++;
            $aktiv += $mitglied->aktiv ? 1 : 0;
        }
        $bekannt = array_filter($alter, static fn(?int $a): bool => $a !== null);

        return new Kennzahlen(
            gesamt: count($aktuelle),
            aktiv: $aktiv,
            passiv: count($aktuelle) - $aktiv,
            weiblich: $geschlecht[0],
            maennlich: $geschlecht[1],
            divers: $geschlecht[2],
            ohneGeschlecht: $geschlecht[3],
            minderjaehrig: count(array_filter($bekannt, static fn(int $a): bool => $a < Hinweise::VOLLJAEHRIG)),
            durchschnittsalterZehntel: Kennzahlen::mittelZehntel(array_sum(array_map(static fn(int $a): int => max(0, $a), $bekannt)), count($bekannt)),
        );
    }

    /**
     * @param list<Mitglied> $aktuelle
     * @param array<int, int|null> $alter
     */
    private static function altersgruppen(array $aktuelle, array $alter): Auswertung
    {
        $zaehler = array_fill_keys(Merkmale::altersgruppen(), [0, 0, 0, 0]);
        foreach ($aktuelle as $mitglied) {
            $zaehler[Altersgruppen::gruppe($alter[(int) $mitglied->id])][Merkmale::geschlecht($mitglied->geschlecht)]++;
        }

        return self::nachGeschlecht(
            'altersgruppen',
            'Altersgruppen',
            'Altersgruppe',
            $zaehler,
            Diagramm::Gestapelt,
            'Alter heute in vollen Jahren, beide Grenzen gehören dazu: „15–18“ sind 15, 16, 17 und 18 Jahre.',
            [Altersgruppen::OHNE_GEBURTSDATUM],
        );
    }

    /**
     * @param list<Mitglied> $aktuelle
     * @param array<int, int|null> $alter
     */
    private static function lebensphasen(array $aktuelle, array $alter): Auswertung
    {
        $zaehler = array_fill_keys([...array_keys(Altersgruppen::LEBENSPHASEN), Altersgruppen::OHNE_GEBURTSDATUM], 0);
        foreach ($aktuelle as $mitglied) {
            $zaehler[Altersgruppen::lebensphase($alter[(int) $mitglied->id])]++;
        }

        return self::einfach(
            'lebensphasen',
            'Kinder, Jugendliche, Erwachsene, Senioren',
            'Gruppe',
            $zaehler,
            Diagramm::Saeulen,
            'Kinder bis 13, Jugendliche 14 bis 17, Erwachsene 18 bis 66, Senioren ab 67 Jahren.',
            [Altersgruppen::OHNE_GEBURTSDATUM],
        );
    }

    /**
     * @param list<Mitglied> $aktuelle
     * @param array<int, int|null> $alter
     * @param list<Abteilung> $abteilungen
     */
    private static function abteilungen(array $aktuelle, array $alter, array $abteilungen): Auswertung
    {
        $ohne = 'ohne Abteilung';
        $namen = [];
        foreach ($abteilungen as $abteilung) {
            $namen[(int) $abteilung->id] = $abteilung->name;
        }
        $zaehler = [];
        $alterSumme = [];
        $alterAnzahl = [];
        foreach ($aktuelle as $mitglied) {
            $eigene = array_values(array_unique(array_filter($mitglied->abteilungIds, static fn(int $id): bool => isset($namen[$id]))));
            foreach ($eigene === [] ? [0] : $eigene as $id) {
                $zaehler[$id] ??= [0, 0, 0, 0];
                $zaehler[$id][Merkmale::geschlecht($mitglied->geschlecht)]++;
                $a = $alter[(int) $mitglied->id];
                if ($a !== null) {
                    $alterSumme[$id] = ($alterSumme[$id] ?? 0) + max(0, $a);
                    $alterAnzahl[$id] = ($alterAnzahl[$id] ?? 0) + 1;
                }
            }
        }

        $zeilen = [];
        $zeile = static fn(int $id, string $name, bool $istOhne = false): AuswertungsZeile => new AuswertungsZeile(
            $name,
            $zaehler[$id] ?? [0, 0, 0, 0],
            $istOhne,
            zusatz: Kennzahlen::zehntel(Kennzahlen::mittelZehntel($alterSumme[$id] ?? 0, $alterAnzahl[$id] ?? 0)) ?? '–',
        );
        foreach ($abteilungen as $abteilung) {
            if ($abteilung->aktiv || isset($zaehler[(int) $abteilung->id])) {
                $zeilen[] = $zeile((int) $abteilung->id, $abteilung->name);
            }
        }
        if (isset($zaehler[0])) {
            $zeilen[] = $zeile(0, $ohne, true);
        }

        return new Auswertung(
            'abteilungen',
            'Abteilungen',
            'Abteilung',
            Merkmale::geschlechter(),
            $zeilen,
            Diagramm::GestapeltLiegend,
            erklaerung: 'Wer in mehreren Abteilungen ist, zählt in jeder – deshalb gibt es keine Summe. „Ø Alter“ in Jahren, nur Mitglieder mit Geburtsdatum.',
            zusatzTitel: 'Ø Alter',
        );
    }

    /**
     * Places or postcodes, top 10 and the rest.
     *
     * @param list<Mitglied> $aktuelle
     * @param 'ort'|'plz' $feld
     */
    private static function wohnorte(array $aktuelle, string $feld, string $id, string $titel, string $merkmal, string $mehrzahl): Auswertung
    {
        $sammlung = [];
        foreach ($aktuelle as $mitglied) {
            Rangliste::zaehlen($sammlung, $mitglied->{$feld}, 1);
        }
        $zeilen = Rangliste::zeilen($sammlung, $mehrzahl);

        return new Auswertung(
            $id,
            $titel,
            $merkmal,
            ['Mitglieder' => 1],
            $zeilen,
            Diagramm::Balken,
            new AuswertungsZeile('Zusammen', [count($aktuelle)]),
            erklaerung: 'Die ' . Rangliste::ANZAHL . ' häufigsten, der Rest unter „Sonstige“.',
        );
    }

    /** @param list<Mitglied> $aktuelle */
    private static function familien(array $aktuelle): Auswertung
    {
        $zaehler = [Merkmale::IN_FAMILIE => 0, Merkmale::EINZELN => 0];
        $familien = [];
        foreach ($aktuelle as $mitglied) {
            $zaehler[Merkmale::familie($mitglied->familieId)]++;
            if ($mitglied->familieId !== null) {
                $familien[$mitglied->familieId] = true;
            }
        }
        $anzahl = count($familien);

        return self::einfach(
            'familien',
            'Familien und Einzelmitglieder',
            'Mitgliedschaft',
            $zaehler,
            Diagramm::Ring,
            ($anzahl === 1 ? '1 Familie' : $anzahl . ' Familien') . ' mit aktuellen Mitgliedern.',
        );
    }

    /** @param list<Mitglied> $aktuelle */
    private static function dauer(array $aktuelle, \DateTimeImmutable $stichtag): Auswertung
    {
        $zaehler = array_fill_keys([...array_keys(self::DAUER), self::DAUER_UNBEKANNT], 0);
        foreach ($aktuelle as $mitglied) {
            $zaehler[self::dauerGruppe($mitglied->eintrittsdatum, $stichtag)]++;
        }

        return self::einfach(
            'dauer',
            'Mitgliedsdauer',
            'Jahre im Verein',
            $zaehler,
            Diagramm::Saeulen,
            'Volle Jahre seit dem Eintrittsdatum.',
            [self::DAUER_UNBEKANNT],
        );
    }

    public static function dauerGruppe(?\DateTimeImmutable $eintritt, \DateTimeImmutable $stichtag): string
    {
        if ($eintritt === null) {
            return self::DAUER_UNBEKANNT;
        }
        $jahre = max(0, Alter::am($eintritt, $stichtag));
        foreach (self::DAUER as $name => [$von, $bis]) {
            if ($jahre >= $von && ($bis === null || $jahre <= $bis)) {
                return $name;
            }
        }

        return self::DAUER_UNBEKANNT;
    }

    /** @param list<Mitglied> $mitglieder all, former ones too */
    private static function einUndAustritte(array $mitglieder, \DateTimeImmutable $stichtag): Auswertung
    {
        $bis = (int) $stichtag->format('Y');
        $zaehler = [];
        for ($jahr = $bis - self::JAHRE + 1; $jahr <= $bis; $jahr++) {
            $zaehler[$jahr] = [0, 0];
        }
        $heute = $stichtag->format('Y-m-d');
        foreach ($mitglieder as $mitglied) {
            if ($mitglied->eintrittsdatum !== null && $mitglied->eintrittsdatum->format('Y-m-d') <= $heute) {
                $jahr = (int) $mitglied->eintrittsdatum->format('Y');
                if (isset($zaehler[$jahr])) {
                    $zaehler[$jahr][0]++;
                }
            }
            // Only exits that happened: a noted future exit is still a member.
            if ($mitglied->istEhemalig($stichtag)) {
                $jahr = (int) $mitglied->austrittsdatum?->format('Y');
                if (isset($zaehler[$jahr])) {
                    $zaehler[$jahr][1]++;
                }
            }
        }

        $zeilen = [];
        foreach ($zaehler as $jahr => $werte) {
            $zeilen[] = new AuswertungsZeile((string) $jahr, $werte);
        }

        return new Auswertung(
            'ein-austritte',
            'Eintritte und Austritte',
            'Jahr',
            ['Eintritte' => 1, 'Austritte' => 3],
            $zeilen,
            Diagramm::Gruppiert,
            new AuswertungsZeile('Zusammen', [array_sum(array_column($zaehler, 0)), array_sum(array_column($zaehler, 1))]),
            erklaerung: 'Die letzten ' . self::JAHRE . ' Jahre nach Eintritts- und Austrittsdatum. Hier zählen auch ehemalige Mitglieder; ein vermerkter Austritt in der Zukunft zählt erst, wenn er erreicht ist.',
        );
    }

    /**
     * Counts by gender as an Auswertung, the totals row included.
     *
     * @param array<string, list<int>> $zaehler name => four counts
     * @param list<string> $ohne names shown as rest rows, left out while 0
     */
    private static function nachGeschlecht(string $id, string $titel, string $merkmal, array $zaehler, Diagramm $diagramm, string $erklaerung, array $ohne = []): Auswertung
    {
        $zeilen = [];
        $summen = [0, 0, 0, 0];
        foreach ($zaehler as $name => $werte) {
            $name = (string) $name;
            $istOhne = in_array($name, $ohne, true);
            foreach ($werte as $i => $wert) {
                $summen[$i] += $wert;
            }
            if (!$istOhne || array_sum($werte) > 0) {
                $zeilen[] = new AuswertungsZeile($name, $werte, $istOhne);
            }
        }

        return new Auswertung($id, $titel, $merkmal, Merkmale::geschlechter(), $zeilen, $diagramm, new AuswertungsZeile('Zusammen', $summen), erklaerung: $erklaerung);
    }

    /**
     * Single counts as an Auswertung, the totals row included.
     *
     * @param array<string, int> $zaehler name => count
     * @param list<string> $ohne names shown as rest rows, left out while 0
     */
    private static function einfach(string $id, string $titel, string $merkmal, array $zaehler, Diagramm $diagramm, string $erklaerung, array $ohne = []): Auswertung
    {
        $zeilen = [];
        foreach ($zaehler as $name => $wert) {
            $name = (string) $name;
            $istOhne = in_array($name, $ohne, true);
            if (!$istOhne || $wert > 0) {
                $zeilen[] = new AuswertungsZeile($name, [$wert], $istOhne);
            }
        }

        return new Auswertung($id, $titel, $merkmal, ['Mitglieder' => 1], $zeilen, $diagramm, new AuswertungsZeile('Zusammen', [array_sum($zaehler)]), erklaerung: $erklaerung);
    }
}
