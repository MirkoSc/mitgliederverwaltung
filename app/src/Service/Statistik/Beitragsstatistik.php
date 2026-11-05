<?php

declare(strict_types=1);

namespace App\Service\Statistik;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\Zahlart;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Beitraege\Beitragsverteilung;
use App\Service\Beitraege\Jahresbeitraege;
use App\Service\Beitraege\Verteilung;
use App\Service\Beitraege\VerteilungsZeile;

/**
 * "Statistik – Beiträge" (docs/spec/09-listen-und-exporte.md section 7,
 * 08 section 2b): where a year's fees come from. Fee type and department
 * come unchanged from App\Service\Beitraege\Beitragsverteilung; this adds
 * gender, age group, place, aktiv/passiv, family and Zahlart.
 *
 * - Basis as in 08 section 2b: the year's created fees, else the
 *   projection. Former members appear exactly when the year has (or would
 *   get) a fee for them - the key date of a fee is 1 January.
 * - Personal features: a fee is split equally over the people behind it -
 *   the member, and for a family payer the family members of the year
 *   without a fee of their own (Beitragsverteilung::traeger()) - in cent by
 *   largest remainder; then summed per feature. Every feature adds up to
 *   the total to the cent.
 * - Age at the key date of the fee (1 January of the year).
 * - Zahlart: the payer's, not split - it is how the fee is paid.
 * - The features are today's (a fee does not store them).
 */
final readonly class Beitragsstatistik
{
    public const string UNBEKANNT = 'Mitglied unbekannt';

    public function __construct(
        private MitgliedRepository $mitglieder,
        private BeitragsartRepository $arten,
        private AbteilungRepository $abteilungen,
        private JahresbeitragRepository $beitraege,
    ) {
    }

    public static function mit(\PDO $pdo): self
    {
        return new self(new MitgliedRepository($pdo), new BeitragsartRepository($pdo), new AbteilungRepository($pdo), new JahresbeitragRepository($pdo));
    }

    public function fuerJahr(int $jahr): Beitragszahlen
    {
        return self::berechnen($jahr, $this->mitglieder->alle(true), $this->arten->alle(), $this->abteilungen->alle(), $this->beitraege->imJahr($jahr));
    }

    /**
     * @param list<Mitglied> $mitglieder all members, former ones too
     * @param list<Beitragsart> $arten in display order
     * @param list<Abteilung> $abteilungen in display order
     * @param list<Jahresbeitrag> $beitraege the year's created fees; none => projection
     */
    public static function berechnen(int $jahr, array $mitglieder, array $arten, array $abteilungen, array $beitraege): Beitragszahlen
    {
        $verteilung = Beitragsverteilung::berechnen($jahr, $mitglieder, $arten, $abteilungen, $beitraege);

        $nachId = [];
        foreach ($mitglieder as $mitglied) {
            $nachId[(int) $mitglied->id] = $mitglied;
        }
        $zahlungen = Beitragsverteilung::zahlungen($jahr, $mitglieder, $arten, $beitraege);
        $familien = Beitragsverteilung::familienOhneBeitrag($jahr, $mitglieder, $zahlungen);

        // Each person's share of the fees: member id => cent.
        $anteile = [];
        $unbekannt = 0;
        $nachZahlart = [];
        foreach ($zahlungen as $id => $cent) {
            $zahler = $nachId[$id] ?? null;
            $traeger = Beitragsverteilung::traeger($zahler, $familien);
            if ($zahler === null || $traeger === []) {
                $unbekannt += $cent;
                continue;
            }
            $gewichte = [];
            foreach ($traeger as $person) {
                $gewichte[(int) $person->id] = 1;
            }
            foreach (Beitragsverteilung::teilen($cent, $gewichte) as $personId => $teil) {
                $anteile[$personId] = ($anteile[$personId] ?? 0) + $teil;
            }
            $nachZahlart[$zahler->zahlart->value] ??= ['wert' => 0, 'personen' => 0];
            $nachZahlart[$zahler->zahlart->value]['wert'] += $cent;
            $nachZahlart[$zahler->zahlart->value]['personen']++;
        }

        $stichtag = Jahresbeitraege::stichtag($jahr);
        $gruppiert = static function (\Closure $merkmal) use ($anteile, $nachId): array {
            $summen = [];
            foreach ($anteile as $id => $cent) {
                $name = $merkmal($nachId[$id]);
                $summen[$name] ??= ['wert' => 0, 'personen' => 0];
                $summen[$name]['wert'] += $cent;
                $summen[$name]['personen']++;
            }

            return $summen;
        };

        $sammlung = [];
        foreach ($anteile as $id => $cent) {
            Rangliste::zaehlen($sammlung, $nachId[$id]->ort, $cent);
        }
        $wohnorte = Rangliste::zeilen($sammlung, 'Orte', mitPersonen: true);

        $zahlarten = [];
        foreach (Zahlart::cases() as $zahlart) {
            $zahlarten[$zahlart->bezeichnung()] = $nachZahlart[$zahlart->value] ?? ['wert' => 0, 'personen' => 0];
        }

        $personen = count($anteile);
        $auswertung = static fn(string $id, string $titel, string $merkmal, array $zeilen, Diagramm $diagramm, string $erklaerung, string $anzahlTitel = 'Personen', ?int $anzahl = null): Auswertung
            => self::geld($verteilung, $id, $titel, $merkmal, $zeilen, $diagramm, $erklaerung, $anzahlTitel, $anzahl ?? $personen, $unbekannt);

        return new Beitragszahlen($verteilung, [
            $auswertung('beitragsart', 'Nach Beitragsart', 'Beitragsart', self::ausVerteilung($verteilung->nachArt), Diagramm::Balken,
                'Die heutige Beitragsart des Mitglieds.', 'Beiträge', $verteilung->anzahl),
            $auswertung('abteilung', 'Nach Abteilung', 'Abteilung', self::ausVerteilung($verteilung->nachAbteilung), Diagramm::Balken,
                'Wer in mehreren Abteilungen ist, zahlt einen Beitrag – er wird gleichmäßig auf seine Abteilungen geteilt. „Personen“ zählt jede Person, die zur Abteilung beiträgt.', anzahl: $verteilung->personen),
            $auswertung('geschlecht', 'Nach Geschlecht', 'Geschlecht', self::feste(array_keys(Merkmale::GESCHLECHTER), $gruppiert(
                static fn(Mitglied $m): string => Merkmale::geschlechtName($m->geschlecht),
            ), ['ohne Angabe']), Diagramm::Balken, ''),
            $auswertung('altersgruppe', 'Nach Altersgruppe', 'Altersgruppe', self::feste(Merkmale::altersgruppen(), $gruppiert(
                static fn(Mitglied $m): string => Altersgruppen::gruppe($m->alterAm($stichtag)),
            ), [Altersgruppen::OHNE_GEBURTSDATUM]), Diagramm::Saeulen, 'Alter am 1. Januar ' . $jahr . ', gleiche Gruppen wie bei den Mitgliedern.'),
            $auswertung('wohnort', 'Nach Wohnort', 'Ort', $wohnorte, Diagramm::Balken,
                'Die ' . Rangliste::ANZAHL . ' Orte mit den höchsten Beträgen, der Rest unter „Sonstige“.'),
            $auswertung('aktiv', 'Aktiv und passiv', 'Mitgliedschaft', self::feste(['aktiv', 'passiv'], $gruppiert(
                static fn(Mitglied $m): string => $m->aktiv ? 'aktiv' : 'passiv',
            )), Diagramm::Balken, ''),
            $auswertung('familie', 'Familien und Einzelmitglieder', 'Mitgliedschaft', self::feste(['in einer Familie', 'einzeln'], $gruppiert(
                static fn(Mitglied $m): string => Merkmale::familie($m->familieId),
            )), Diagramm::Ring, ''),
            $auswertung('zahlart', 'Nach Zahlart', 'Zahlart', self::feste(array_keys($zahlarten), $zahlarten), Diagramm::Ring,
                'Wie der Beitrag bezahlt wird – die Zahlart des Zahlers, nicht geteilt. „Beiträge“ zählt die Beiträge.', 'Beiträge', $verteilung->anzahl),
        ]);
    }

    /**
     * @param list<VerteilungsZeile> $zeilen
     * @return list<AuswertungsZeile>
     */
    private static function ausVerteilung(array $zeilen): array
    {
        return array_map(
            static fn(VerteilungsZeile $z): AuswertungsZeile => new AuswertungsZeile($z->name, [$z->summeCent], $z->ohne, $z->anzahl),
            $zeilen,
        );
    }

    /**
     * Rows in a fixed order; a rest row only when it has something.
     *
     * @param list<string> $namen
     * @param array<string, array{wert: int, personen: int}> $summen
     * @param list<string> $ohne
     * @return list<AuswertungsZeile>
     */
    private static function feste(array $namen, array $summen, array $ohne = []): array
    {
        $zeilen = [];
        foreach ($namen as $name) {
            $istOhne = in_array($name, $ohne, true);
            $summe = $summen[$name] ?? ['wert' => 0, 'personen' => 0];
            if (!$istOhne || $summe['personen'] > 0) {
                $zeilen[] = new AuswertungsZeile($name, [$summe['wert']], $istOhne, $summe['personen']);
            }
        }

        return $zeilen;
    }

    /**
     * An Auswertung in cent with its totals row. Fees of members no longer
     * known (none in practice) get their own rest row so every evaluation
     * still adds up to the total.
     *
     * @param list<AuswertungsZeile> $zeilen
     */
    private static function geld(Verteilung $verteilung, string $id, string $titel, string $merkmal, array $zeilen, Diagramm $diagramm, string $erklaerung, string $anzahlTitel, int $zusammenAnzahl, int $unbekannt): Auswertung
    {
        $summe = array_sum(array_map(static fn(AuswertungsZeile $z): int => $z->summe(), $zeilen));
        if ($summe < $verteilung->summeCent && $unbekannt > 0) {
            $zeilen[] = new AuswertungsZeile(self::UNBEKANNT, [$verteilung->summeCent - $summe], true);
        }
        return new Auswertung(
            $id,
            $titel,
            $merkmal,
            ['Summe' => 1],
            $zeilen,
            $diagramm,
            new AuswertungsZeile('Zusammen', [$verteilung->summeCent], anzahl: $zusammenAnzahl),
            geld: true,
            erklaerung: $erklaerung,
            anzahlTitel: $anzahlTitel,
        );
    }
}
