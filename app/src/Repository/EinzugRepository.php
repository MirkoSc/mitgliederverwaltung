<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Einzug;
use App\Domain\EinzugStatus;

/**
 * Direct debit runs (docs/spec/08-beitraege.md sections 3 and 6). Never
 * deleted - there is deliberately no method for it.
 */
final readonly class EinzugRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** Inserts the run (its id goes into the EndToEndIds of the file). */
    public function anlegen(Einzug $einzug): Einzug
    {
        $this->pdo->prepare(
            'INSERT INTO einzug (status, faellig_am, erstellt_von, erstellt_am, bestaetigt_am, bestaetigt_von,
                                 anzahl, summe_cent, msg_id, datei_blob_id, daten)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $einzug->status->value,
            Zeilen::fuerDatum($einzug->faelligAm),
            $einzug->erstelltVon,
            Zeilen::fuerZeitpunkt($einzug->erstelltAm),
            $einzug->bestaetigtAm === null ? null : Zeilen::fuerZeitpunkt($einzug->bestaetigtAm),
            $einzug->bestaetigtVon,
            $einzug->anzahl,
            $einzug->summeCent,
            $einzug->msgId,
            $einzug->dateiBlobId,
            Zeilen::json($einzug->daten),
        ]);

        return $einzug->mit(['id' => (int) $this->pdo->lastInsertId()]);
    }

    /**
     * Stores the file once, right after it was created - with the
     * transactions as they went into it, when they name the run's id (the
     * EndToEndIds). A run that already has its file keeps it - the archive
     * is never changed (08 section 3); false then.
     *
     * @param array<mixed>|null $daten null = keep what anlegen() stored
     */
    public function dateiSetzen(int $id, int $blobId, string $msgId, ?array $daten = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE einzug SET datei_blob_id = ?, msg_id = ?, daten = COALESCE(?, daten) WHERE id = ? AND datei_blob_id IS NULL',
        );
        $stmt->execute([$blobId, $msgId, $daten === null ? null : Zeilen::json($daten), $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * `erzeugt` → `bestaetigt` or `verworfen`, once; false when the run was
     * no longer `erzeugt`.
     */
    public function abschliessen(int $id, EinzugStatus $status, ?int $userId, ?\DateTimeImmutable $jetzt = null): bool
    {
        if ($status === EinzugStatus::Erzeugt) {
            throw new \LogicException('A run can only be confirmed or discarded.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE einzug SET status = ?, bestaetigt_am = ?, bestaetigt_von = ? WHERE id = ? AND status = ?',
        );
        $stmt->execute([$status->value, Zeilen::fuerZeitpunkt($jetzt), $userId, $id, EinzugStatus::Erzeugt->value]);

        return $stmt->rowCount() === 1;
    }

    public function finde(int $id): ?Einzug
    {
        $stmt = $this->pdo->prepare('SELECT * FROM einzug WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::einzug($zeile) : null;
    }

    /**
     * The archive: every run, discarded ones included, newest first.
     *
     * @return list<Einzug>
     */
    public function alle(): array
    {
        return array_map(
            self::einzug(...),
            $this->pdo->query('SELECT * FROM einzug ORDER BY erstellt_am DESC, id DESC')->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array<string, mixed> $z
     */
    private static function einzug(array $z): Einzug
    {
        $faellig = Zeilen::datum($z['faellig_am']);
        assert($faellig !== null);

        return new Einzug(
            status: EinzugStatus::tryFrom((string) $z['status']) ?? EinzugStatus::Erzeugt,
            faelligAm: $faellig,
            erstelltVon: Zeilen::idOderNull($z['erstellt_von']),
            erstelltAm: Zeilen::zeitpunkt($z['erstellt_am']),
            anzahl: (int) $z['anzahl'],
            summeCent: (int) $z['summe_cent'],
            msgId: (string) $z['msg_id'],
            dateiBlobId: Zeilen::idOderNull($z['datei_blob_id']),
            daten: Zeilen::jsonArray($z['daten']),
            bestaetigtAm: Zeilen::zeitpunktOderNull($z['bestaetigt_am']),
            bestaetigtVon: Zeilen::idOderNull($z['bestaetigt_von']),
            id: (int) $z['id'],
        );
    }
}
