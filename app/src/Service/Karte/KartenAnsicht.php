<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * What the page "Karte" shows (issue #91), built from the (filtered)
 * members and the known coordinates: the addresses on the map, the ones the
 * geocoder did not find, the ones not asked yet, and members without an
 * address. Several members at one address are one entry.
 */
final readonly class KartenAnsicht
{
    /**
     * @param list<KartenOrt> $gefunden
     * @param list<KartenOrt> $nichtGefunden
     * @param list<KartenOrt> $offen
     * @param list<KartenMitglied> $ohneAnschrift
     */
    private function __construct(
        public array $gefunden,
        public array $nichtGefunden,
        public array $offen,
        public array $ohneAnschrift,
    ) {
    }

    /**
     * @param list<KartenMitglied> $mitglieder
     * @param array<string, array{status: ?GeoStatus, lat: ?float, lon: ?float, korrektur?: ?array{strasse: string, plz: string, ort: string}}> $koordinaten by address hash
     */
    public static function aus(array $mitglieder, array $koordinaten): self
    {
        $gruppen = [];
        $ohneAnschrift = [];
        foreach ($mitglieder as $mitglied) {
            if ($mitglied->anschrift->istLeer()) {
                $ohneAnschrift[] = $mitglied;
                continue;
            }
            $hash = $mitglied->anschrift->hash();
            $gruppen[$hash] ??= ['anschrift' => $mitglied->anschrift, 'mitglieder' => []];
            $gruppen[$hash]['mitglieder'][] = $mitglied;
        }

        $gefunden = [];
        $nichtGefunden = [];
        $offen = [];
        foreach ($gruppen as $hash => $gruppe) {
            $geo = $koordinaten[$hash] ?? null;
            if ($geo !== null && $geo['status'] === GeoStatus::Gefunden && $geo['lat'] !== null && $geo['lon'] !== null) {
                $gefunden[] = new KartenOrt($gruppe['anschrift'], $gruppe['mitglieder'], $geo['lat'], $geo['lon']);
            } elseif ($geo !== null && $geo['status'] === GeoStatus::NichtGefunden) {
                $nichtGefunden[] = new KartenOrt($gruppe['anschrift'], $gruppe['mitglieder']);
            } else {
                $offen[] = new KartenOrt($gruppe['anschrift'], $gruppe['mitglieder']);
            }
        }

        return new self(self::sortiert($gefunden), self::sortiert($nichtGefunden), self::sortiert($offen), $ohneAnschrift);
    }

    public function anzahlMitglieder(): int
    {
        $anzahl = count($this->ohneAnschrift);
        foreach ([...$this->gefunden, ...$this->nichtGefunden, ...$this->offen] as $ort) {
            $anzahl += count($ort->mitglieder);
        }

        return $anzahl;
    }

    /**
     * By town, then street - the order a reader of the text list expects.
     *
     * @param list<KartenOrt> $orte
     * @return list<KartenOrt>
     */
    private static function sortiert(array $orte): array
    {
        // Natural order: "Hauptstraße 2" before "Hauptstraße 10".
        $vergleich = static fn(string $a, string $b): int => strnatcasecmp($a, $b);
        usort($orte, static fn(KartenOrt $a, KartenOrt $b): int => $vergleich($a->anschrift->ort, $b->anschrift->ort)
            ?: $vergleich($a->anschrift->plz, $b->anschrift->plz)
            ?: $vergleich($a->anschrift->strasse, $b->anschrift->strasse));

        return $orte;
    }
}
