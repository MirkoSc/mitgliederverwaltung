<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\JahresbeitragStatus;

/**
 * The open fees of one year with the member's payment data, for the list
 * "Selbst kassieren" (docs/spec/09-listen-und-exporte.md section 2). Only
 * the filters that are plain SQL run here (year, status, former members);
 * whether a member pays by themselves or has no valid IBAN is decided in
 * App\Listen\SelbstKassierenListe, next to the IBAN rule of the member.
 */
final readonly class SelbstKassierenRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> raw rows, by name
     */
    public function offeneBeitraege(int $jahr, \DateTimeImmutable $heute): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.betrag_cent, j.jahr, m.id AS mitglied_id, m.nachname, m.vorname,
                    m.telefon, m.handy, m.email, m.zahlart, m.iban
             FROM jahresbeitrag j JOIN mitglied m ON m.id = j.mitglied_id
             WHERE j.jahr = ? AND j.status = ?
               AND (m.austrittsdatum IS NULL OR m.austrittsdatum > ?)
             ORDER BY m.nachname, m.vorname, m.mitgliedsnummer',
        );
        $stmt->execute([$jahr, JahresbeitragStatus::Offen->value, $heute->format('Y-m-d')]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
