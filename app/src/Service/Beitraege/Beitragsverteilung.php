<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;

/**
 * "Verteilung der Beiträge" (docs/spec/08-beitraege.md section 2b): how much
 * a year's fees bring in, by fee type and by department.
 *
 * - Basis: the year's created fees (every status); a year without any is
 *   projected the way "Beiträge erzeugen" would create it.
 * - Fee type: the member's current one (a created fee does not store it).
 * - Department: one fee split equally over the member's departments; a
 *   family payer's fee first equally over the payer and every family
 *   member of that year without a fee of their own. Without a department:
 *   the row "ohne Abteilung". The department sums add up to the total,
 *   to the cent (largest remainder).
 */
final readonly class Beitragsverteilung
{
    /** Key of the rows "ohne Beitragsart" and "ohne Abteilung" - no real id is 0. */
    private const int OHNE = 0;

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

    public function fuerJahr(int $jahr): Verteilung
    {
        return self::berechnen($jahr, $this->mitglieder->alle(true), $this->arten->alle(), $this->abteilungen->alle(), $this->beitraege->imJahr($jahr));
    }

    /**
     * @param list<Mitglied> $mitglieder all members, former ones too
     * @param list<Beitragsart> $arten in display order
     * @param list<Abteilung> $abteilungen in display order
     * @param list<Jahresbeitrag> $beitraege the year's created fees; none => projection
     */
    public static function berechnen(int $jahr, array $mitglieder, array $arten, array $abteilungen, array $beitraege): Verteilung
    {
        $nachId = [];
        foreach ($mitglieder as $mitglied) {
            $nachId[(int) $mitglied->id] = $mitglied;
        }
        $artNachId = [];
        foreach ($arten as $art) {
            $artNachId[(int) $art->id] = $art;
        }

        // Who pays how much: member id => cent.
        $zahlungen = [];
        $hochgerechnet = $beitraege === [];
        if ($hochgerechnet) {
            foreach ($mitglieder as $mitglied) {
                $cent = Jahresbeitraege::vorschlag($mitglied, $artNachId[$mitglied->beitragsartId ?? self::OHNE] ?? null, $jahr);
                if ($cent !== null) {
                    $zahlungen[(int) $mitglied->id] = $cent;
                }
            }
        } else {
            foreach ($beitraege as $beitrag) {
                $zahlungen[$beitrag->mitgliedId] = $beitrag->betragCent;
            }
        }

        // Family members of the year without a fee of their own, by family.
        $familien = [];
        foreach ($mitglieder as $mitglied) {
            if ($mitglied->familieId !== null && !isset($zahlungen[(int) $mitglied->id]) && Jahresbeitraege::istImJahrMitglied($mitglied, $jahr)) {
                $familien[$mitglied->familieId][] = $mitglied;
            }
        }

        $abteilungIds = [];
        foreach ($abteilungen as $abteilung) {
            $abteilungIds[(int) $abteilung->id] = true;
        }

        $jeArt = [];
        $summeAbteilung = [];
        $personenAbteilung = [];
        $personen = [];
        foreach ($zahlungen as $id => $cent) {
            $mitglied = $nachId[$id] ?? null;
            $artId = $mitglied?->beitragsartId;
            $artKey = $artId !== null && isset($artNachId[$artId]) ? $artId : self::OHNE;
            $jeArt[$artKey] ??= ['anzahl' => 0, 'summe' => 0];
            $jeArt[$artKey]['anzahl']++;
            $jeArt[$artKey]['summe'] += $cent;

            $traeger = $mitglied === null ? [] : [$mitglied, ...self::familieOhneBeitrag($mitglied, $familien)];
            $gewichte = [];
            $kgv = 1;
            foreach ($traeger as $person) {
                $kgv = self::kgv($kgv, count(self::abteilungenVon($person, $abteilungIds)));
            }
            foreach ($traeger as $person) {
                $eigene = self::abteilungenVon($person, $abteilungIds);
                foreach ($eigene as $key) {
                    $gewichte[$key] = ($gewichte[$key] ?? 0) + intdiv($kgv, count($eigene));
                    $personenAbteilung[$key][(int) $person->id] = true;
                }
                $personen[(int) $person->id] = true;
            }
            foreach (self::teilen($cent, $gewichte === [] ? [self::OHNE => 1] : $gewichte) as $key => $anteil) {
                $summeAbteilung[$key] = ($summeAbteilung[$key] ?? 0) + $anteil;
            }
        }

        $nachArt = [];
        foreach ($arten as $art) {
            if (isset($jeArt[(int) $art->id])) {
                $nachArt[] = new VerteilungsZeile($art->name, $jeArt[(int) $art->id]['anzahl'], $jeArt[(int) $art->id]['summe']);
            }
        }
        if (isset($jeArt[self::OHNE])) {
            $nachArt[] = new VerteilungsZeile('ohne Beitragsart', $jeArt[self::OHNE]['anzahl'], $jeArt[self::OHNE]['summe'], ohne: true);
        }

        $nachAbteilung = [];
        foreach ($abteilungen as $abteilung) {
            $key = (int) $abteilung->id;
            if ($abteilung->aktiv || isset($summeAbteilung[$key])) {
                $nachAbteilung[] = new VerteilungsZeile($abteilung->name, count($personenAbteilung[$key] ?? []), $summeAbteilung[$key] ?? 0);
            }
        }
        if (isset($summeAbteilung[self::OHNE])) {
            $nachAbteilung[] = new VerteilungsZeile('ohne Abteilung', count($personenAbteilung[self::OHNE] ?? []), $summeAbteilung[self::OHNE], ohne: true);
        }

        return new Verteilung(
            jahr: $jahr,
            hochgerechnet: $hochgerechnet,
            anzahl: count($zahlungen),
            summeCent: array_sum($zahlungen),
            personen: count($personen),
            nachArt: $nachArt,
            nachAbteilung: $nachAbteilung,
        );
    }

    /**
     * $cent split by integer weights, the parts adding up exactly: each key
     * gets its rounded-down share, the cents left over go to the largest
     * remainders (equal remainders: first key first).
     *
     * @param non-empty-array<int, int> $gewichte key => weight > 0
     * @return array<int, int> key => cent
     */
    public static function teilen(int $cent, array $gewichte): array
    {
        $gesamt = array_sum($gewichte);
        $teile = [];
        $reste = [];
        foreach ($gewichte as $key => $gewicht) {
            $teile[$key] = intdiv($cent * $gewicht, $gesamt);
            $reste[$key] = ($cent * $gewicht) % $gesamt;
        }
        $uebrig = $cent - array_sum($teile);
        // arsort is stable since PHP 8.0: equal remainders keep their order.
        arsort($reste);
        foreach (array_slice(array_keys($reste), 0, $uebrig) as $key) {
            $teile[$key]++;
        }

        return $teile;
    }

    /**
     * @param array<int, list<Mitglied>> $familien
     * @return list<Mitglied>
     */
    private static function familieOhneBeitrag(Mitglied $zahler, array $familien): array
    {
        if ($zahler->familieId === null || $zahler->familieRolle !== FamilienRolle::Zahler) {
            return [];
        }

        // The payer has a fee, so is never among them.
        return $familien[$zahler->familieId] ?? [];
    }

    /**
     * The member's departments that exist; none => "ohne Abteilung".
     *
     * @param array<int, true> $abteilungIds
     * @return non-empty-list<int>
     */
    private static function abteilungenVon(Mitglied $mitglied, array $abteilungIds): array
    {
        $eigene = array_values(array_unique(array_filter($mitglied->abteilungIds, static fn(int $id): bool => isset($abteilungIds[$id]))));

        return $eigene === [] ? [self::OHNE] : $eigene;
    }

    private static function kgv(int $a, int $b): int
    {
        [$x, $y] = [$a, $b];
        while ($y !== 0) {
            [$x, $y] = [$y, $x % $y];
        }

        return intdiv($a, $x) * $b;
    }
}
