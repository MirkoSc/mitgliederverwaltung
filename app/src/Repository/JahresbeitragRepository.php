<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\JahresbeitragZeile;
use App\Domain\Vermerk;
use App\Domain\Zahlweise;

/**
 * Annual fees (docs/spec/08-beitraege.md sections 2 and 6), unique per
 * member and year. Not versioned (07 section 7); the note list is their
 * history.
 */
final readonly class JahresbeitragRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Creates the fee unless the member already has one for that year - so
     * "Beiträge erzeugen" can be pressed again and again (08 section 2).
     * Returns the new fee, null when there already was one.
     */
    public function anlegenWennFehlt(Jahresbeitrag $beitrag): ?Jahresbeitrag
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO jahresbeitrag (mitglied_id, jahr, betrag_cent, status, einzug_id, zahlweise, bezahlt_am, vermerke)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $beitrag->mitgliedId,
            $beitrag->jahr,
            $beitrag->betragCent,
            $beitrag->status->value,
            $beitrag->einzugId,
            $beitrag->zahlweise?->value,
            Zeilen::fuerDatum($beitrag->bezahltAm),
            self::vermerkeJson($beitrag->vermerke),
        ]);

        return $stmt->rowCount() === 1 ? $beitrag->mit(['id' => (int) $this->pdo->lastInsertId()]) : null;
    }

    public function finde(int $id): ?Jahresbeitrag
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE id = ?', [$id])[0] ?? null;
    }

    /**
     * Like finde(), with the row locked until the transaction ends - for a
     * read-change-save, so two changes at once cannot lose each other's
     * note. Call inside a transaction.
     */
    public function findeZumAendern(int $id): ?Jahresbeitrag
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
    }

    public function fuer(int $mitgliedId, int $jahr): ?Jahresbeitrag
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE mitglied_id = ? AND jahr = ?', [$mitgliedId, $jahr])[0] ?? null;
    }

    /**
     * The tab "Beiträge": all years of a member, newest first.
     *
     * @return list<Jahresbeitrag>
     */
    public function fuerMitglied(int $mitgliedId): array
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE mitglied_id = ? ORDER BY jahr DESC', [$mitgliedId]);
    }

    /**
     * The fees of a year, optionally of one status only, by member id.
     *
     * @return list<Jahresbeitrag>
     */
    public function imJahr(int $jahr, ?JahresbeitragStatus $status = null): array
    {
        return $status === null
            ? $this->mehrere('SELECT * FROM jahresbeitrag WHERE jahr = ? ORDER BY mitglied_id', [$jahr])
            : $this->mehrere('SELECT * FROM jahresbeitrag WHERE jahr = ? AND status = ? ORDER BY mitglied_id', [$jahr, $status->value]);
    }

    /**
     * The fees page (08 section 2): every fee of a year with the name of its
     * member, by name.
     *
     * @return list<JahresbeitragZeile>
     */
    public function zeilenImJahr(int $jahr): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.*, m.mitgliedsnummer, m.nachname, m.vorname
             FROM jahresbeitrag j JOIN mitglied m ON m.id = j.mitglied_id
             WHERE j.jahr = ?
             ORDER BY m.nachname, m.vorname, m.mitgliedsnummer',
        );
        $stmt->execute([$jahr]);

        return array_map(
            static fn(array $z): JahresbeitragZeile => new JahresbeitragZeile(
                beitrag: self::jahresbeitrag($z),
                mitgliedsnummer: (int) $z['mitgliedsnummer'],
                nachname: (string) $z['nachname'],
                vorname: (string) $z['vorname'],
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * All fees with that status over all years (e.g. every open one for the
     * direct debit), by year and member.
     *
     * @return list<Jahresbeitrag>
     */
    public function mitStatus(JahresbeitragStatus $status): array
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE status = ? ORDER BY jahr, mitglied_id', [$status->value]);
    }

    /**
     * @return list<Jahresbeitrag>
     */
    public function desEinzugs(int $einzugId): array
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE einzug_id = ? ORDER BY jahr, mitglied_id', [$einzugId]);
    }

    /**
     * Like desEinzugs(), the rows locked until the transaction ends - for
     * confirming or discarding a direct debit (#30). Call inside a
     * transaction.
     *
     * @return list<Jahresbeitrag>
     */
    public function desEinzugsZumAendern(int $einzugId): array
    {
        return $this->mehrere('SELECT * FROM jahresbeitrag WHERE einzug_id = ? ORDER BY jahr, mitglied_id FOR UPDATE', [$einzugId]);
    }

    /**
     * The fees with these ids that still exist, by id - e.g. the fees of an
     * archived direct debit (#30). $zumAendern locks the rows until the
     * transaction ends (call inside one), for taking them into a direct
     * debit.
     *
     * @param list<int> $ids
     * @return array<int, Jahresbeitrag>
     */
    public function mitIds(array $ids, bool $zumAendern = false): array
    {
        $gefunden = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $teil) {
            $sql = 'SELECT * FROM jahresbeitrag WHERE id IN (' . Zeilen::platzhalter(count($teil)) . ') ORDER BY id'
                . ($zumAendern ? ' FOR UPDATE' : '');
            foreach ($this->mehrere($sql, $teil) as $beitrag) {
                $gefunden[(int) $beitrag->id] = $beitrag;
            }
        }

        return $gefunden;
    }

    /**
     * Writes amount, status, direct debit, payment and notes of an existing
     * fee. Which transition is allowed (amount only while `offen`, 08
     * section 2) is decided by the service that changes it; this only stores.
     */
    public function speichern(Jahresbeitrag $beitrag): void
    {
        if ($beitrag->id === null) {
            throw new \LogicException('Use anlegenWennFehlt() for a new annual fee.');
        }

        $this->pdo->prepare(
            'UPDATE jahresbeitrag
             SET betrag_cent = ?, status = ?, einzug_id = ?, zahlweise = ?, bezahlt_am = ?, vermerke = ?
             WHERE id = ?',
        )->execute([
            $beitrag->betragCent,
            $beitrag->status->value,
            $beitrag->einzugId,
            $beitrag->zahlweise?->value,
            Zeilen::fuerDatum($beitrag->bezahltAm),
            self::vermerkeJson($beitrag->vermerke),
            $beitrag->id,
        ]);
    }

    /**
     * @param list<mixed> $werte
     * @return list<Jahresbeitrag>
     */
    private function mehrere(string $sql, array $werte): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($werte);

        return array_map(self::jahresbeitrag(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param list<Vermerk> $vermerke
     */
    private static function vermerkeJson(array $vermerke): string
    {
        return Zeilen::json(array_map(static fn(Vermerk $v): array => $v->alsArray(), $vermerke));
    }

    /**
     * @param array<string, mixed> $z
     */
    private static function jahresbeitrag(array $z): Jahresbeitrag
    {
        return new Jahresbeitrag(
            mitgliedId: (int) $z['mitglied_id'],
            jahr: (int) $z['jahr'],
            betragCent: (int) $z['betrag_cent'],
            status: JahresbeitragStatus::tryFrom((string) $z['status']) ?? JahresbeitragStatus::Offen,
            einzugId: Zeilen::idOderNull($z['einzug_id']),
            zahlweise: $z['zahlweise'] === null ? null : Zahlweise::tryFrom((string) $z['zahlweise']),
            bezahltAm: Zeilen::datum($z['bezahlt_am']),
            vermerke: array_values(array_map(
                static fn(mixed $v): Vermerk => Vermerk::ausArray(is_array($v) ? $v : []),
                Zeilen::jsonArray($z['vermerke']),
            )),
            id: (int) $z['id'],
        );
    }
}
