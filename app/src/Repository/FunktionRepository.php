<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Funktion;

/**
 * Functions (docs/spec/07-mitglieder.md section 4), ordered by
 * `sortierung`, then name.
 */
final readonly class FunktionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Funktion>
     */
    public function alle(bool $nurAktive = false): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM funktion' . ($nurAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY sortierung, name, id',
        );

        return array_map(self::funktion(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function finde(int $id): ?Funktion
    {
        $stmt = $this->pdo->prepare('SELECT * FROM funktion WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::funktion($zeile) : null;
    }

    /** Inserts (id null) or updates; returns it with its id. */
    public function speichern(Funktion $funktion): Funktion
    {
        $werte = [$funktion->name, (int) $funktion->mitLizenz, $funktion->sortierung, (int) $funktion->aktiv];
        if ($funktion->id === null) {
            $this->pdo->prepare('INSERT INTO funktion (name, mit_lizenz, sortierung, aktiv) VALUES (?, ?, ?, ?)')
                ->execute($werte);

            return clone($funktion, ['id' => (int) $this->pdo->lastInsertId()]);
        }

        $this->pdo->prepare('UPDATE funktion SET name = ?, mit_lizenz = ?, sortierung = ?, aktiv = ? WHERE id = ?')
            ->execute([...$werte, $funktion->id]);

        return $funktion;
    }

    public function wirdVerwendet(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mitglied_funktion WHERE funktion_id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Deletes the function if no member has it; otherwise deactivates it
     * (07 section 4). True when it was deleted.
     */
    public function loeschen(int $id): bool
    {
        return Zeilen::transaktion($this->pdo, function () use ($id): bool {
            if ($this->wirdVerwendet($id)) {
                $this->pdo->prepare('UPDATE funktion SET aktiv = 0 WHERE id = ?')->execute([$id]);

                return false;
            }
            $this->pdo->prepare('DELETE FROM funktion WHERE id = ?')->execute([$id]);

            return true;
        });
    }

    /**
     * @param array<string, mixed> $zeile
     */
    private static function funktion(array $zeile): Funktion
    {
        return new Funktion(
            name: (string) $zeile['name'],
            mitLizenz: (bool) $zeile['mit_lizenz'],
            sortierung: (int) $zeile['sortierung'],
            aktiv: (bool) $zeile['aktiv'],
            id: (int) $zeile['id'],
        );
    }
}
