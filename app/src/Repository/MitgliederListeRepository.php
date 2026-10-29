<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MitgliederFilter;
use App\Domain\MitgliederSeite;
use App\Domain\MitgliederSortierung;
use App\Domain\MitgliederStatus;
use App\Domain\MitgliedZeile;

/**
 * The member list (docs/spec/06-oberflaeche.md sections 2 and 3, 07 section
 * 8): search, filters, sort order and paging in SQL, and only the columns
 * the list shows - not complete members (that is MitgliedRepository).
 *
 * The search field looks in Nachname, Vorname, Mitgliedsnummer, Ort and
 * E-Mail with `LIKE`; every word of the text must occur in one of them, so
 * "max muster" finds Max Mustermann. Case does not matter (collation).
 */
final readonly class MitgliederListeRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * One page. A page beyond the last one shows the last (a filter may have
     * made the list shorter than the page the visitor was on).
     */
    public function seite(MitgliederFilter $filter, ?\DateTimeImmutable $stichtag = null): MitgliederSeite
    {
        $stichtag ??= new \DateTimeImmutable();
        [$bedingung, $werte] = $this->bedingungen($filter, $stichtag);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mitglied m' . $bedingung);
        $stmt->execute($werte);
        $gesamt = (int) $stmt->fetchColumn();

        $proSeite = $filter->anzahl; // 0 = all on one page
        $seiten = $proSeite === 0 ? 1 : max(1, (int) ceil($gesamt / $proSeite));
        $seite = min($filter->seite, $seiten);

        $stmt = $this->pdo->prepare(sprintf(
            'SELECT m.id, m.mitgliedsnummer, m.nachname, m.vorname, m.plz, m.ort, m.aktiv, m.austrittsdatum,
                    COALESCE(b.name, \'\') AS beitragsart
             FROM mitglied m LEFT JOIN beitragsart b ON b.id = m.beitragsart_id%s
             ORDER BY %s%s',
            $bedingung,
            self::reihenfolge($filter),
            $proSeite === 0 ? '' : sprintf(' LIMIT %d OFFSET %d', $proSeite, ($seite - 1) * $proSeite),
        ));
        $stmt->execute($werte);
        $zeilen = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $abteilungen = $this->abteilungen(array_map(static fn(array $zeile): int => (int) $zeile['id'], $zeilen));
        $heute = Zeilen::fuerDatum($stichtag);

        return new MitgliederSeite(
            array_map(
                static fn(array $z): MitgliedZeile => new MitgliedZeile(
                    id: (int) $z['id'],
                    mitgliedsnummer: (int) $z['mitgliedsnummer'],
                    nachname: (string) $z['nachname'],
                    vorname: (string) $z['vorname'],
                    plz: (string) $z['plz'],
                    ort: (string) $z['ort'],
                    aktiv: (bool) $z['aktiv'],
                    ehemalig: $z['austrittsdatum'] !== null && (string) $z['austrittsdatum'] <= $heute,
                    beitragsart: (string) $z['beitragsart'],
                    abteilungen: $abteilungen[(int) $z['id']] ?? [],
                ),
                $zeilen,
            ),
            $gesamt,
            $seite,
            $proSeite,
        );
    }

    /**
     * The WHERE clause (with a leading blank, or '') and its values.
     *
     * @return array{string, list<string|int>}
     */
    private function bedingungen(MitgliederFilter $filter, \DateTimeImmutable $stichtag): array
    {
        $bedingungen = [];
        $werte = [];

        foreach ($filter->woerter() as $wort) {
            $bedingungen[] = '(m.nachname LIKE ? OR m.vorname LIKE ? OR CAST(m.mitgliedsnummer AS CHAR) LIKE ? OR m.ort LIKE ? OR m.email LIKE ?)';
            array_push($werte, ...array_fill(0, 5, '%' . addcslashes($wort, '%_\\') . '%'));
        }
        if ($filter->abteilungId !== null) {
            $bedingungen[] = 'EXISTS (SELECT 1 FROM mitglied_abteilung ma WHERE ma.mitglied_id = m.id AND ma.abteilung_id = ?)';
            $werte[] = $filter->abteilungId;
        }
        if ($filter->status !== MitgliederStatus::Alle) {
            $bedingungen[] = 'm.aktiv = ?';
            $werte[] = $filter->status === MitgliederStatus::Aktiv ? 1 : 0;
        }
        if ($filter->beitragsartId !== null) {
            $bedingungen[] = 'm.beitragsart_id = ?';
            $werte[] = $filter->beitragsartId;
        }
        if (!$filter->mitEhemaligen) {
            $bedingungen[] = '(m.austrittsdatum IS NULL OR m.austrittsdatum > ?)';
            $werte[] = Zeilen::fuerDatum($stichtag);
        }

        return [$bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen), $werte];
    }

    /** The ORDER BY of the chosen column; the name and the number settle ties. */
    private static function reihenfolge(MitgliederFilter $filter): string
    {
        $richtung = $filter->absteigend ? ' DESC' : '';
        $spalten = match ($filter->sortierung) {
            MitgliederSortierung::Name => ['m.nachname', 'm.vorname', 'm.mitgliedsnummer'],
            MitgliederSortierung::Nummer => ['m.mitgliedsnummer'],
            MitgliederSortierung::Ort => ['m.ort', 'm.nachname', 'm.vorname', 'm.mitgliedsnummer'],
            // Members without a fee type come last when ascending.
            MitgliederSortierung::Beitragsart => ['(b.name IS NULL)', 'b.name', 'm.nachname', 'm.vorname', 'm.mitgliedsnummer'],
        };

        return implode(', ', array_map(static fn(string $spalte): string => $spalte . $richtung, $spalten));
    }

    /**
     * Department names per member, in the order of the settings.
     *
     * @param list<int> $mitgliedIds
     * @return array<int, list<string>>
     */
    private function abteilungen(array $mitgliedIds): array
    {
        if ($mitgliedIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT ma.mitglied_id, a.name FROM mitglied_abteilung ma JOIN abteilung a ON a.id = ma.abteilung_id
             WHERE ma.mitglied_id IN (%s) ORDER BY a.sortierung, a.name, a.id',
            Zeilen::platzhalter(count($mitgliedIds)),
        ));
        $stmt->execute($mitgliedIds);

        $namen = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
            $namen[(int) $zeile['mitglied_id']][] = (string) $zeile['name'];
        }

        return $namen;
    }
}
