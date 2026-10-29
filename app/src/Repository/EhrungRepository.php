<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Ehrung;

/**
 * The note "geehrt" (docs/spec/09-listen-und-exporte.md section 2): at most
 * one per member and level, and it can be taken back.
 */
final readonly class EhrungRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Ehrung>
     */
    public function fuerMitglied(int $mitgliedId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ehrung WHERE mitglied_id = ? ORDER BY stufe_jahre');
        $stmt->execute([$mitgliedId]);

        return array_map(self::ehrung(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * All notes of one level - what the honours list of a year needs to
     * mark "geehrt" and to hide them with "nur offene". By member id.
     *
     * @return array<int, Ehrung>
     */
    public function derStufe(int $stufeJahre): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ehrung WHERE stufe_jahre = ?');
        $stmt->execute([$stufeJahre]);
        $ergebnis = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
            $ergebnis[(int) $zeile['mitglied_id']] = self::ehrung($zeile);
        }

        return $ergebnis;
    }

    /**
     * Notes "geehrt"; a second note for the same level keeps the first and
     * returns false.
     */
    public function vermerken(int $mitgliedId, int $stufeJahre, \DateTimeImmutable $geehrtAm, ?int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO ehrung (mitglied_id, stufe_jahre, geehrt_am, vermerkt_von) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$mitgliedId, $stufeJahre, Zeilen::fuerDatum($geehrtAm), $userId]);

        return $stmt->rowCount() === 1;
    }

    /** Takes the note back; true when there was one. */
    public function zuruecknehmen(int $mitgliedId, int $stufeJahre): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ehrung WHERE mitglied_id = ? AND stufe_jahre = ?');
        $stmt->execute([$mitgliedId, $stufeJahre]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $z
     */
    private static function ehrung(array $z): Ehrung
    {
        $geehrtAm = Zeilen::datum($z['geehrt_am']);
        assert($geehrtAm !== null);

        return new Ehrung(
            mitgliedId: (int) $z['mitglied_id'],
            stufeJahre: (int) $z['stufe_jahre'],
            geehrtAm: $geehrtAm,
            vermerktVon: Zeilen::idOderNull($z['vermerkt_von']),
            id: (int) $z['id'],
        );
    }
}
