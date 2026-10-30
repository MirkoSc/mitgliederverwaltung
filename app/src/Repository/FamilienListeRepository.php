<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\FamilienRolle;
use App\Domain\FamilienZeile;
use App\Domain\Wohnanschrift;

/**
 * The list of the families page (issue #92): every family with its payer,
 * the number of persons and whether their addresses differ - read in one
 * query (a club has a few hundred families at most).
 */
final readonly class FamilienListeRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Families matching $text: every word must occur in the family's name
     * or in the first or last name of one of its persons (so "Lena" finds
     * her family). Case does not matter (collation). Ordered by name.
     *
     * @return list<FamilienZeile>
     */
    public function liste(string $text = ''): array
    {
        $bedingungen = [];
        $werte = [];
        foreach (preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $wort) {
            $muster = '%' . addcslashes($wort, '%_\\') . '%';
            $bedingungen[] = '(f.name LIKE ? OR EXISTS (SELECT 1 FROM mitglied s WHERE s.familie_id = f.id AND (s.nachname LIKE ? OR s.vorname LIKE ?)))';
            array_push($werte, $muster, $muster, $muster);
        }

        $stmt = $this->pdo->prepare(
            'SELECT f.id AS fid, f.name AS fname, m.id AS mid, m.titel, m.vorname, m.nachname, m.namenszusatz,
                    m.familie_rolle, m.strasse, m.plz, m.ort, m.land
             FROM familie f LEFT JOIN mitglied m ON m.familie_id = f.id'
            . ($bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen))
            . " ORDER BY f.name, f.id, FIELD(m.familie_rolle, 'zahler', 'elternteil', 'kind'), m.nachname, m.vorname",
        );
        $stmt->execute($werte);

        /** @var array<int, array{name: string, anzahl: int, zahler: list<string>, anschriften: list<Wohnanschrift>}> $familien */
        $familien = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $id = (int) $z['fid'];
            $familien[$id] ??= ['name' => (string) $z['fname'], 'anzahl' => 0, 'zahler' => [], 'anschriften' => []];
            if ($z['mid'] === null) {
                continue;
            }
            $familien[$id]['anzahl']++;
            if ($z['familie_rolle'] === FamilienRolle::Zahler->value) {
                $familien[$id]['zahler'][] = implode(' ', array_filter(
                    [(string) $z['titel'], (string) $z['vorname'], (string) $z['nachname'], (string) $z['namenszusatz']],
                    static fn(string $teil): bool => $teil !== '',
                ));
            }
            $familien[$id]['anschriften'][] = new Wohnanschrift((string) $z['strasse'], (string) $z['plz'], (string) $z['ort'], (string) $z['land']);
        }

        $zeilen = [];
        foreach ($familien as $id => $f) {
            $erste = $f['anschriften'][0] ?? null;
            $abweichend = false;
            foreach ($f['anschriften'] as $anschrift) {
                $abweichend = $abweichend || ($erste !== null && !$anschrift->gleich($erste));
            }
            $zeilen[] = new FamilienZeile($id, $f['name'], $f['anzahl'], $f['zahler'], $abweichend);
        }

        return $zeilen;
    }
}
