<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\FamilienRolle;
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
 * The search field looks in the name, number, address, contact, bank and
 * remark fields with `LIKE`, and in the date of birth; every word of the
 * text must match in one of them, so "max muster" finds Max Mustermann and
 * "Müller 1980" finds the Müller born in 1980 (07 section 8). Case does not
 * matter (collation).
 */
final readonly class MitgliederListeRepository
{
    /** The text columns a search word may occur in ("mitgliedsnummer" is a number, see wortBedingung()). */
    private const array SUCHSPALTEN = [
        'm.nachname', 'm.vorname', 'm.strasse', 'm.plz', 'm.ort', 'm.email',
        'm.telefon', 'm.handy', 'm.kontoinhaber', 'm.bemerkung',
    ];

    /** Shortest word compared with the IBAN: a piece of two characters would hit nearly every IBAN. */
    private const int IBAN_MINDESTLAENGE = 4;

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
                    COALESCE(b.name, \'\') AS beitragsart, %s AS ueber_familie
             FROM mitglied m LEFT JOIN beitragsart b ON b.id = m.beitragsart_id
                  LEFT JOIN familie f ON f.id = m.familie_id%s
             ORDER BY %s%s',
            // Found only through the family: the fee type is not the member's own.
            $filter->beitragsartId === null
                ? "''"
                : sprintf("CASE WHEN m.beitragsart_id <=> %d THEN '' ELSE COALESCE(f.name, '') END", $filter->beitragsartId),
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
                    ueberFamilie: (string) $z['ueber_familie'],
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
            [$bedingung, $wortWerte] = self::wortBedingung($wort, $stichtag);
            $bedingungen[] = $bedingung;
            array_push($werte, ...$wortWerte);
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
            // The members of a family pay through its payer: a fee type
            // also finds the members of the families whose payer has it.
            $bedingungen[] = '(m.beitragsart_id = ? OR (m.familie_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM mitglied z WHERE z.familie_id = m.familie_id AND z.familie_rolle = ? AND z.beitragsart_id = ?)))';
            array_push($werte, $filter->beitragsartId, FamilienRolle::Zahler->value, $filter->beitragsartId);
        }
        if (!$filter->mitEhemaligen) {
            $bedingungen[] = '(m.austrittsdatum IS NULL OR m.austrittsdatum > ?)';
            $werte[] = Zeilen::fuerDatum($stichtag);
        }

        return [$bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen), $werte];
    }

    /**
     * The condition one search word must fulfil: it occurs in a text
     * column or the member number, or - when it looks like one - it is the
     * date of birth, the year of birth or a piece of the IBAN.
     *
     * @return array{string, list<string|int>}
     */
    private static function wortBedingung(string $wort, \DateTimeImmutable $stichtag): array
    {
        $muster = '%' . addcslashes($wort, '%_\\') . '%';
        $teile = array_map(static fn(string $spalte): string => $spalte . ' LIKE ?', self::SUCHSPALTEN);
        $teile[] = 'CAST(m.mitgliedsnummer AS CHAR) LIKE ?';
        $werte = array_fill(0, count($teile), $muster);

        // IBAN: without blanks and independent of case, a piece is enough.
        if (preg_match('/^[A-Za-z0-9]{' . self::IBAN_MINDESTLAENGE . ',}$/D', $wort) === 1) {
            $teile[] = "UPPER(REPLACE(m.iban, ' ', '')) LIKE ?";
            $werte[] = '%' . strtoupper($wort) . '%';
        }

        $datum = self::geburtsdatum($wort, $stichtag);
        if ($datum !== null) {
            $teile[] = 'm.geburtsdatum = ?';
            $werte[] = $datum;
        } elseif (preg_match('/^[0-9]{4}$/D', $wort) === 1) {
            $teile[] = 'm.geburtsdatum BETWEEN ? AND ?';
            array_push($werte, $wort . '-01-01', $wort . '-12-31');
        }

        return ['(' . implode(' OR ', $teile) . ')', $werte];
    }

    /**
     * The date a word stands for (`1.2.1980`, `01.02.1980`, `1980-02-01`,
     * `1.2.80`) as `YYYY-MM-DD`, or null when it is no valid date. A year
     * of two digits is in this century unless that lies in the future
     * (born "80" is 1980, born "10" is 2010).
     */
    private static function geburtsdatum(string $wort, \DateTimeImmutable $stichtag): ?string
    {
        if (preg_match('/^([0-9]{1,2})\.([0-9]{1,2})\.([0-9]{4}|[0-9]{2})$/D', $wort, $t) === 1) {
            [$tag, $monat, $jahr] = [(int) $t[1], (int) $t[2], $t[3]];
        } elseif (preg_match('/^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})$/D', $wort, $t) === 1) {
            [$tag, $monat, $jahr] = [(int) $t[3], (int) $t[2], $t[1]];
        } else {
            return null;
        }

        if (strlen($jahr) === 2) {
            $jahr = (int) $jahr + 2000 > (int) $stichtag->format('Y') ? 1900 + (int) $jahr : 2000 + (int) $jahr;
        }
        $jahr = (int) $jahr;

        return checkdate($monat, $tag, $jahr) ? sprintf('%04d-%02d-%02d', $jahr, $monat, $tag) : null;
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
