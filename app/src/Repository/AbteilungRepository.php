<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Abteilung;

/**
 * Departments (docs/spec/07-mitglieder.md section 2), ordered by
 * `sortierung`, then name.
 */
final readonly class AbteilungRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Abteilung>
     */
    public function alle(bool $nurAktive = false): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM abteilung' . ($nurAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY sortierung, name, id',
        );

        return array_map(self::abteilung(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function finde(int $id): ?Abteilung
    {
        $stmt = $this->pdo->prepare('SELECT * FROM abteilung WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::abteilung($zeile) : null;
    }

    /** Inserts (id null) or updates; returns it with its id. */
    public function speichern(Abteilung $abteilung): Abteilung
    {
        $werte = [$abteilung->name, $abteilung->blsvNummer, $abteilung->sortierung, (int) $abteilung->aktiv];
        if ($abteilung->id === null) {
            $this->pdo->prepare('INSERT INTO abteilung (name, blsv_nummer, sortierung, aktiv) VALUES (?, ?, ?, ?)')
                ->execute($werte);

            return clone($abteilung, ['id' => (int) $this->pdo->lastInsertId()]);
        }

        $this->pdo->prepare('UPDATE abteilung SET name = ?, blsv_nummer = ?, sortierung = ?, aktiv = ? WHERE id = ?')
            ->execute([...$werte, $abteilung->id]);

        return $abteilung;
    }

    public function wirdVerwendet(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mitglied_abteilung WHERE abteilung_id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Deletes the department if no member has it; otherwise deactivates it
     * (07 section 2). True when it was deleted.
     */
    public function loeschen(int $id): bool
    {
        return Zeilen::transaktion($this->pdo, function () use ($id): bool {
            if ($this->wirdVerwendet($id)) {
                $this->pdo->prepare('UPDATE abteilung SET aktiv = 0 WHERE id = ?')->execute([$id]);

                return false;
            }
            $this->pdo->prepare('DELETE FROM abteilung WHERE id = ?')->execute([$id]);

            return true;
        });
    }

    /**
     * @param array<string, mixed> $zeile
     */
    private static function abteilung(array $zeile): Abteilung
    {
        return new Abteilung(
            name: (string) $zeile['name'],
            blsvNummer: (string) $zeile['blsv_nummer'],
            sortierung: (int) $zeile['sortierung'],
            aktiv: (bool) $zeile['aktiv'],
            id: (int) $zeile['id'],
        );
    }
}
