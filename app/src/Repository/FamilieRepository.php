<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Familie;

/**
 * Families (docs/spec/07-mitglieder.md section 3). Who belongs to a family
 * is stored at the member (MitgliedRepository::derFamilie()); a family
 * without members is deleted by MitgliedRepository when its last member
 * leaves.
 */
final readonly class FamilieRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function anlegen(string $name, ?string $importSchluessel = null, ?\DateTimeImmutable $jetzt = null): Familie
    {
        $zeit = Zeilen::fuerZeitpunkt($jetzt);
        $this->pdo->prepare('INSERT INTO familie (name, import_schluessel, created_at) VALUES (?, ?, ?)')
            ->execute([$name, $importSchluessel, $zeit]);

        return new Familie((int) $this->pdo->lastInsertId(), $name, $importSchluessel, Zeilen::zeitpunkt($zeit));
    }

    public function finde(int $id): ?Familie
    {
        $stmt = $this->pdo->prepare('SELECT * FROM familie WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::familie($zeile) : null;
    }

    /** By the FamID of the old software (docs/spec/10-import.md). */
    public function findeNachImportSchluessel(string $schluessel): ?Familie
    {
        $stmt = $this->pdo->prepare('SELECT * FROM familie WHERE import_schluessel = ?');
        $stmt->execute([$schluessel]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::familie($zeile) : null;
    }

    /**
     * "Familie wählen": families whose name contains the text
     * (case-insensitive), by name; all of them for ''.
     *
     * @return list<Familie>
     */
    public function suche(string $text, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM familie WHERE name LIKE ? ORDER BY name, id LIMIT ' . max(1, $limit),
        );
        $stmt->execute(['%' . addcslashes(trim($text), '%_\\') . '%']);

        return array_map(self::familie(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function umbenennen(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE familie SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /** Deletes the family if nobody belongs to it any more; true when it was deleted. */
    public function loeschenWennLeer(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM familie WHERE id = ? AND NOT EXISTS (SELECT 1 FROM mitglied WHERE familie_id = ?)',
        );
        $stmt->execute([$id, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $zeile
     */
    private static function familie(array $zeile): Familie
    {
        return new Familie(
            id: (int) $zeile['id'],
            name: (string) $zeile['name'],
            importSchluessel: $zeile['import_schluessel'] === null ? null : (string) $zeile['import_schluessel'],
            createdAt: Zeilen::zeitpunkt($zeile['created_at']),
        );
    }
}
