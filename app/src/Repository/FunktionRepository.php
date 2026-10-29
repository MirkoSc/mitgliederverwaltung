<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Funktion;
use App\Domain\FunktionsZuordnung;

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

            return $funktion->mit(['id' => (int) $this->pdo->lastInsertId()]);
        }

        $this->pdo->prepare('UPDATE funktion SET name = ?, mit_lizenz = ?, sortierung = ?, aktiv = ? WHERE id = ?')
            ->execute([...$werte, $funktion->id]);

        return $funktion;
    }

    /**
     * One row per member and function for the list "Funktionen" (09 section 2):
     * former members left out, filtered by function in SQL, ordered by the
     * function's order and then by the member's name.
     *
     * @return list<FunktionsZuordnung>
     */
    public function zuordnungen(?int $funktionId, \DateTimeImmutable $stichtag): array
    {
        $bedingungen = ['(m.austrittsdatum IS NULL OR m.austrittsdatum > ?)'];
        $werte = [Zeilen::fuerDatum($stichtag)];
        if ($funktionId !== null) {
            $bedingungen[] = 'f.id = ?';
            $werte[] = $funktionId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT m.id AS mitglied_id, m.nachname, m.vorname, m.lizenznummer, m.telefon, m.handy, m.email,
                    f.id AS funktion_id, f.name AS funktion,
                    COALESCE((SELECT GROUP_CONCAT(a.name ORDER BY a.sortierung, a.name, a.id SEPARATOR ', ')
                              FROM mitglied_abteilung ma JOIN abteilung a ON a.id = ma.abteilung_id
                              WHERE ma.mitglied_id = m.id), '') AS abteilungen
             FROM mitglied_funktion mf
             JOIN mitglied m ON m.id = mf.mitglied_id
             JOIN funktion f ON f.id = mf.funktion_id
             WHERE " . implode(' AND ', $bedingungen) . '
             ORDER BY f.sortierung, f.name, f.id, m.nachname, m.vorname, m.id',
        );
        $stmt->execute($werte);

        return array_map(
            static fn(array $z): FunktionsZuordnung => new FunktionsZuordnung(
                mitgliedId: (int) $z['mitglied_id'],
                nachname: (string) $z['nachname'],
                vorname: (string) $z['vorname'],
                funktionId: (int) $z['funktion_id'],
                funktion: (string) $z['funktion'],
                lizenznummer: (string) $z['lizenznummer'],
                abteilungen: (string) $z['abteilungen'],
                telefon: (string) $z['telefon'],
                handy: (string) $z['handy'],
                email: (string) $z['email'],
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
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
