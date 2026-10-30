<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MitgliederStatus;
use App\Service\Karte\Anschrift;
use App\Service\Karte\KartenMitglied;

/**
 * The members of the page "Karte" (issue #91): current ones only - a former
 * member (austrittsdatum reached) has no place on the map, and its address
 * leaves geo_anschrift with the next run. Filters like the member list:
 * department and aktiv/passiv.
 */
final readonly class KartenRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<KartenMitglied> ordered by name
     */
    public function mitglieder(\DateTimeImmutable $stichtag, ?int $abteilungId = null, MitgliederStatus $status = MitgliederStatus::Alle): array
    {
        $bedingungen = ['(m.austrittsdatum IS NULL OR m.austrittsdatum > ?)'];
        $werte = [Zeilen::fuerDatum($stichtag)];
        if ($abteilungId !== null) {
            $bedingungen[] = 'EXISTS (SELECT 1 FROM mitglied_abteilung ma WHERE ma.mitglied_id = m.id AND ma.abteilung_id = ?)';
            $werte[] = $abteilungId;
        }
        if ($status !== MitgliederStatus::Alle) {
            $bedingungen[] = 'm.aktiv = ?';
            $werte[] = $status === MitgliederStatus::Aktiv ? 1 : 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.mitgliedsnummer, m.vorname, m.nachname, m.strasse, m.plz, m.ort, m.land, m.aktiv
             FROM mitglied m WHERE ' . implode(' AND ', $bedingungen) . '
             ORDER BY m.nachname, m.vorname, m.mitgliedsnummer',
        );
        $stmt->execute($werte);

        return array_map(
            static fn(array $z): KartenMitglied => new KartenMitglied(
                id: (int) $z['id'],
                mitgliedsnummer: (int) $z['mitgliedsnummer'],
                vorname: (string) $z['vorname'],
                nachname: (string) $z['nachname'],
                anschrift: new Anschrift((string) $z['strasse'], (string) $z['plz'], (string) $z['ort'], (string) $z['land']),
                aktiv: (bool) $z['aktiv'],
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * Every address of the current members, once each, in member order -
     * what the geocoding works through. Addresses without postcode and town
     * are left out: there is nothing to look up.
     *
     * @return array<string, Anschrift> by hash
     */
    public function anschriften(\DateTimeImmutable $stichtag): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT strasse, plz, ort, land FROM mitglied
             WHERE austrittsdatum IS NULL OR austrittsdatum > ?
             ORDER BY id',
        );
        $stmt->execute([Zeilen::fuerDatum($stichtag)]);

        $anschriften = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $anschrift = new Anschrift((string) $z['strasse'], (string) $z['plz'], (string) $z['ort'], (string) $z['land']);
            if (!$anschrift->istLeer()) {
                $anschriften[$anschrift->hash()] ??= $anschrift;
            }
        }

        return $anschriften;
    }
}
