<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AktivArt;
use App\Domain\Beitragsart;
use App\Domain\BeitragsRegel;

/**
 * Fee types (docs/spec/08-beitraege.md section 1), ordered by `sortierung`,
 * then name.
 */
final readonly class BeitragsartRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Beitragsart>
     */
    public function alle(bool $nurAktive = false): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM beitragsart' . ($nurAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY sortierung, name, id',
        );

        return array_map(self::beitragsart(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function finde(int $id): ?Beitragsart
    {
        $stmt = $this->pdo->prepare('SELECT * FROM beitragsart WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::beitragsart($zeile) : null;
    }

    /** Inserts (id null) or updates; returns it with its id. */
    public function speichern(Beitragsart $art): Beitragsart
    {
        $werte = [$art->name, $art->betragCent, $art->regel?->value, $art->aktivArt->value, $art->sortierung, (int) $art->aktiv];
        if ($art->id === null) {
            $this->pdo->prepare(
                'INSERT INTO beitragsart (name, betrag_cent, regel, aktiv_art, sortierung, aktiv) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute($werte);

            return clone($art, ['id' => (int) $this->pdo->lastInsertId()]);
        }

        $this->pdo->prepare(
            'UPDATE beitragsart SET name = ?, betrag_cent = ?, regel = ?, aktiv_art = ?, sortierung = ?, aktiv = ? WHERE id = ?',
        )->execute([...$werte, $art->id]);

        return $art;
    }

    public function wirdVerwendet(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mitglied WHERE beitragsart_id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Deletes the fee type if no member has it; otherwise deactivates it
     * (08 section 1). True when it was deleted. Annual fees keep their own
     * amount and do not refer to the fee type.
     */
    public function loeschen(int $id): bool
    {
        return Zeilen::transaktion($this->pdo, function () use ($id): bool {
            if ($this->wirdVerwendet($id)) {
                $this->pdo->prepare('UPDATE beitragsart SET aktiv = 0 WHERE id = ?')->execute([$id]);

                return false;
            }
            $this->pdo->prepare('DELETE FROM beitragsart WHERE id = ?')->execute([$id]);

            return true;
        });
    }

    /**
     * @param array<string, mixed> $zeile
     */
    private static function beitragsart(array $zeile): Beitragsart
    {
        return new Beitragsart(
            name: (string) $zeile['name'],
            betragCent: (int) $zeile['betrag_cent'],
            regel: $zeile['regel'] === null ? null : BeitragsRegel::tryFrom((string) $zeile['regel']),
            aktivArt: AktivArt::tryFrom((string) $zeile['aktiv_art']) ?? AktivArt::Egal,
            sortierung: (int) $zeile['sortierung'],
            aktiv: (bool) $zeile['aktiv'],
            id: (int) $zeile['id'],
        );
    }
}
