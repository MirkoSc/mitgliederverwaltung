<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Nachweis;
use App\Domain\NachweisArt;

/**
 * Proofs (docs/spec/07-mitglieder.md section 5). Not versioned; who noted
 * a proof and when is set on insert and never changed. The file is a blob
 * the caller stores before and deletes after (loeschen() returns its id).
 */
final readonly class NachweisRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The proofs of a member, newest first.
     *
     * @return list<Nachweis>
     */
    public function fuerMitglied(int $mitgliedId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nachweis WHERE mitglied_id = ? ORDER BY vermerkt_am DESC, id DESC');
        $stmt->execute([$mitgliedId]);

        return array_map(self::nachweis(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The proofs of many members at once (hints for a whole list), by member id.
     *
     * @param list<int> $mitgliedIds
     * @return array<int, list<Nachweis>>
     */
    public function fuerMitglieder(array $mitgliedIds): array
    {
        $ergebnis = [];
        foreach (array_chunk(array_values(array_unique($mitgliedIds)), 1000) as $teil) {
            $stmt = $this->pdo->prepare(sprintf(
                'SELECT * FROM nachweis WHERE mitglied_id IN (%s) ORDER BY vermerkt_am DESC, id DESC',
                Zeilen::platzhalter(count($teil)),
            ));
            $stmt->execute($teil);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
                $ergebnis[(int) $zeile['mitglied_id']][] = self::nachweis($zeile);
            }
        }

        return $ergebnis;
    }

    public function finde(int $id): ?Nachweis
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nachweis WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::nachweis($zeile) : null;
    }

    /**
     * Notes a proof; `vermerktAm` is set here ($jetzt, default now), whatever
     * the object says.
     */
    public function anlegen(Nachweis $nachweis, ?\DateTimeImmutable $jetzt = null): Nachweis
    {
        $zeit = Zeilen::fuerZeitpunkt($jetzt);
        $this->pdo->prepare(
            'INSERT INTO nachweis (mitglied_id, art, gueltig_bis, bemerkung, blob_id, vermerkt_von, vermerkt_am)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $nachweis->mitgliedId,
            $nachweis->art->value,
            Zeilen::fuerDatum($nachweis->gueltigBis),
            $nachweis->bemerkung,
            $nachweis->blobId,
            $nachweis->vermerktVon,
            $zeit,
        ]);

        return clone($nachweis, ['id' => (int) $this->pdo->lastInsertId(), 'vermerktAm' => Zeilen::zeitpunkt($zeit)]);
    }

    /**
     * Deletes a proof (admin only). Returns the blob id of its file for the
     * caller to delete, null when it had none or did not exist.
     */
    public function loeschen(int $id): ?int
    {
        return Zeilen::transaktion($this->pdo, function () use ($id): ?int {
            $stmt = $this->pdo->prepare('SELECT blob_id FROM nachweis WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $blob = $stmt->fetchColumn();
            $this->pdo->prepare('DELETE FROM nachweis WHERE id = ?')->execute([$id]);

            return $blob === false ? null : Zeilen::idOderNull($blob);
        });
    }

    /**
     * @param array<string, mixed> $z
     */
    private static function nachweis(array $z): Nachweis
    {
        return new Nachweis(
            mitgliedId: (int) $z['mitglied_id'],
            art: NachweisArt::tryFrom((string) $z['art']) ?? NachweisArt::Sonstiges,
            gueltigBis: Zeilen::datum($z['gueltig_bis']),
            bemerkung: (string) $z['bemerkung'],
            blobId: Zeilen::idOderNull($z['blob_id']),
            vermerktVon: Zeilen::idOderNull($z['vermerkt_von']),
            vermerktAm: Zeilen::zeitpunkt($z['vermerkt_am']),
            id: (int) $z['id'],
        );
    }
}
