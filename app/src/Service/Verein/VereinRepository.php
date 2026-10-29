<?php

declare(strict_types=1);

namespace App\Service\Verein;

use App\Dienste;
use App\Domain\Verband;
use App\Repository\SettingRepository;

/**
 * Club data (docs/spec/11-vereinsdaten.md): the fields as one JSON setting
 * `verein`, the associations in the table `verband`. Public information,
 * stored unencrypted.
 */
final readonly class VereinRepository
{
    private const string EINSTELLUNG = 'verein';

    public function __construct(private \PDO $pdo, private SettingRepository $settings)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d->pdo(), $d->einstellungen()));
    }

    public function get(): Vereinsdaten
    {
        $gespeichert = $this->settings->get(self::EINSTELLUNG);

        return is_array($gespeichert) ? Vereinsdaten::ausGespeichertem($gespeichert) : Vereinsdaten::leer();
    }

    public function speichern(Vereinsdaten $daten): void
    {
        $this->settings->set(self::EINSTELLUNG, $daten->alsListe());
    }

    /** @return list<Verband> in the saved order */
    public function verbaende(): array
    {
        $zeilen = $this->pdo->query('SELECT id, name, vereinsnummer, bemerkung, sortierung FROM verband ORDER BY sortierung, id');
        $verbaende = [];
        foreach ($zeilen ?: [] as $zeile) {
            $verbaende[] = new Verband((int) $zeile['id'], $zeile['name'], $zeile['vereinsnummer'], $zeile['bemerkung'], (int) $zeile['sortierung']);
        }

        return $verbaende;
    }

    /** The first association with this name (case-insensitive), e.g. "BLSV". */
    public function verbandMitName(string $name): ?Verband
    {
        foreach ($this->verbaende() as $verband) {
            if (mb_strtolower($verband->name) === mb_strtolower($name)) {
                return $verband;
            }
        }

        return null;
    }

    /**
     * Replaces the list by the given rows, in this order: a row with an id
     * that still exists is updated (the id stays), a row without id is
     * added, an existing row that is missing is deleted. One transaction.
     *
     * @param list<array{id: int, name: string, vereinsnummer: string, bemerkung: string}> $zeilen id 0 = new
     */
    public function verbaendeErsetzen(array $zeilen): void
    {
        $this->pdo->beginTransaction();
        try {
            $vorhanden = array_map(static fn(Verband $v): int => $v->id, $this->verbaende());
            $behalten = [];
            $aendern = $this->pdo->prepare('UPDATE verband SET name = ?, vereinsnummer = ?, bemerkung = ?, sortierung = ? WHERE id = ?');
            $anlegen = $this->pdo->prepare('INSERT INTO verband (name, vereinsnummer, bemerkung, sortierung) VALUES (?, ?, ?, ?)');
            foreach (array_values($zeilen) as $reihenfolge => $zeile) {
                if (in_array($zeile['id'], $vorhanden, true) && !in_array($zeile['id'], $behalten, true)) {
                    $aendern->execute([$zeile['name'], $zeile['vereinsnummer'], $zeile['bemerkung'], $reihenfolge, $zeile['id']]);
                    $behalten[] = $zeile['id'];
                } else {
                    $anlegen->execute([$zeile['name'], $zeile['vereinsnummer'], $zeile['bemerkung'], $reihenfolge]);
                }
            }
            $loeschen = $this->pdo->prepare('DELETE FROM verband WHERE id = ?');
            foreach (array_diff($vorhanden, $behalten) as $id) {
                $loeschen->execute([$id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
