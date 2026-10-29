<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Herkunft;
use App\Domain\Mitglied;
use App\Domain\MitgliedVersion;
use App\Domain\SpeicherErgebnis;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlart;
use App\Support\Iban;

/**
 * Members with their departments, functions and versions
 * (docs/spec/07-mitglieder.md sections 1, 7 and 8).
 *
 * Every save that changes something writes a version - the complete state
 * plus who, when and why - in the same transaction; a save without a change
 * writes nothing. The column `mitglied.version` is the number of the latest
 * version and doubles as an optimistic lock: a save based on an older
 * version fails with VersionKonfliktException instead of silently
 * overwriting what somebody else saved in the meantime.
 */
final readonly class MitgliedRepository
{
    /** The member columns in the order speichern() writes them. */
    private const array SPALTEN = [
        'mitgliedsnummer', 'anrede', 'titel', 'vorname', 'nachname', 'namenszusatz', 'geschlecht',
        'geburtsdatum', 'strasse', 'plz', 'ort', 'land', 'telefon', 'handy', 'email', 'eintrittsdatum',
        'austrittsdatum', 'austrittsgrund', 'aktiv', 'beitragsart_id', 'zahlart', 'iban', 'bic',
        'kontoinhaber', 'mandatsreferenz', 'mandatsdatum', 'familie_id', 'familie_rolle', 'lizenznummer',
        'bemerkung', 'herkunft',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public function finde(int $id): ?Mitglied
    {
        return $this->eines('SELECT * FROM mitglied WHERE id = ?', [$id]);
    }

    public function findeNachNummer(int $mitgliedsnummer): ?Mitglied
    {
        return $this->eines('SELECT * FROM mitglied WHERE mitgliedsnummer = ?', [$mitgliedsnummer]);
    }

    /** How many members there are, former ones included. */
    public function anzahl(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM mitglied')->fetchColumn();
    }

    /** Member number for a new member: the highest + 1 (07 section 1). */
    public function naechsteNummer(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(mitgliedsnummer), 0) + 1 FROM mitglied')->fetchColumn();
    }

    /**
     * The search field (07 section 8): every word of $text must occur in
     * Nachname, Vorname, Mitgliedsnummer, Ort or E-Mail - "max muster" finds
     * Max Mustermann. Case does not matter (collation). Former members only
     * with $mitEhemaligen. Ordered by name.
     *
     * @return list<Mitglied>
     */
    public function suche(
        string $text,
        bool $mitEhemaligen = false,
        ?\DateTimeImmutable $stichtag = null,
        int $limit = 500,
    ): array {
        $bedingungen = [];
        $werte = [];
        foreach (preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $wort) {
            $bedingungen[] = '(nachname LIKE ? OR vorname LIKE ? OR CAST(mitgliedsnummer AS CHAR) LIKE ? OR ort LIKE ? OR email LIKE ?)';
            array_push($werte, ...array_fill(0, 5, '%' . addcslashes($wort, '%_\\') . '%'));
        }
        if (!$mitEhemaligen) {
            $bedingungen[] = '(austrittsdatum IS NULL OR austrittsdatum > ?)';
            $werte[] = Zeilen::fuerDatum($stichtag ?? new \DateTimeImmutable());
        }

        return $this->mehrere(
            'SELECT * FROM mitglied'
            . ($bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen))
            . ' ORDER BY nachname, vorname, mitgliedsnummer LIMIT ' . max(1, $limit),
            $werte,
        );
    }

    /**
     * All members, former ones only with $mitEhemaligen.
     *
     * @return list<Mitglied>
     */
    public function alle(bool $mitEhemaligen = false, ?\DateTimeImmutable $stichtag = null): array
    {
        return $this->suche('', $mitEhemaligen, $stichtag, PHP_INT_MAX);
    }

    /**
     * The members of a family, the payer first.
     *
     * @return list<Mitglied>
     */
    public function derFamilie(int $familieId): array
    {
        return $this->mehrere(
            "SELECT * FROM mitglied WHERE familie_id = ?
             ORDER BY FIELD(familie_rolle, 'zahler', 'elternteil', 'kind'), geburtsdatum, nachname, vorname",
            [$familieId],
        );
    }

    /**
     * Saves a new member (id null) or a change, with a version when
     * something changed. The IBAN is normalised (upper case, no spaces) and
     * kept even when its check digits are wrong - that is a hint, not a
     * block (07 section 1).
     *
     * A family the member leaves is deleted when nobody is left in it
     * (07 section 3).
     *
     * @throws VersionKonfliktException the member was saved by somebody else
     *         since $mitglied was read, or does not exist any more
     * @throws MitgliedsnummerVergebenException
     */
    public function speichern(
        Mitglied $mitglied,
        VersionsAnlass $anlass,
        ?int $userId,
        ?\DateTimeImmutable $jetzt = null,
    ): SpeicherErgebnis {
        $mitglied = $mitglied->mit([
            'iban' => Iban::normalisieren($mitglied->iban),
            'bic' => strtoupper((string) preg_replace('/\s+/u', '', $mitglied->bic)),
        ]);
        $zeit = Zeilen::fuerZeitpunkt($jetzt);

        try {
            return Zeilen::transaktion(
                $this->pdo,
                fn(): SpeicherErgebnis => $mitglied->id === null
                    ? $this->einfuegen($mitglied, $anlass, $userId, $zeit)
                    : $this->aendern($mitglied, $anlass, $userId, $zeit),
            );
        } catch (\PDOException $fehler) {
            // Two saves racing for the same number: the unique key decides.
            if (($fehler->errorInfo[1] ?? null) === 1062 && str_contains($fehler->getMessage(), 'uq_mitglied_nummer')) {
                throw new MitgliedsnummerVergebenException($mitglied->mitgliedsnummer);
            }

            throw $fehler;
        }
    }

    /**
     * "Zurückholen" (07 section 7): takes the values of version $nr as a NEW
     * version at the end; the old versions stay untouched. What no longer
     * exists - the family, the fee type, a department or function - stays
     * empty, and so does the member number when another member has it now;
     * each such case is reported in SpeicherErgebnis::$hinweise.
     *
     * @throws \OutOfBoundsException member or version not found
     */
    public function wiederherstellen(
        int $mitgliedId,
        int $nr,
        ?int $userId,
        ?\DateTimeImmutable $jetzt = null,
    ): SpeicherErgebnis {
        return Zeilen::transaktion($this->pdo, function () use ($mitgliedId, $nr, $userId, $jetzt): SpeicherErgebnis {
            $aktuell = $this->finde($mitgliedId);
            $version = $this->version($mitgliedId, $nr);
            if ($aktuell === null || $version === null) {
                throw new \OutOfBoundsException('Member or version not found.');
            }

            $alt = Mitglied::ausDaten($version->daten, $aktuell);
            $hinweise = [];

            if ($alt->familieId !== null && !$this->gibtEs('familie', $alt->familieId)) {
                $alt = $alt->mit(['familieId' => null, 'familieRolle' => null]);
                $hinweise[] = 'Die Familie gibt es nicht mehr; das Mitglied ist jetzt ohne Familie.';
            }
            if ($alt->beitragsartId !== null && !$this->gibtEs('beitragsart', $alt->beitragsartId)) {
                $alt = $alt->mit(['beitragsartId' => null]);
                $hinweise[] = 'Die Beitragsart gibt es nicht mehr; das Mitglied ist jetzt ohne Beitragsart.';
            }
            $abteilungen = $this->vorhandene('abteilung', $alt->abteilungIds);
            if ($abteilungen !== $alt->abteilungIds) {
                $alt = $alt->mit(['abteilungIds' => $abteilungen]);
                $hinweise[] = 'Nicht mehr vorhandene Abteilungen wurden weggelassen.';
            }
            $funktionen = $this->vorhandene('funktion', $alt->funktionIds);
            if ($funktionen !== $alt->funktionIds) {
                $alt = $alt->mit(['funktionIds' => $funktionen]);
                $hinweise[] = 'Nicht mehr vorhandene Funktionen wurden weggelassen.';
            }
            if ($alt->mitgliedsnummer !== $aktuell->mitgliedsnummer) {
                $andere = $this->findeNachNummer($alt->mitgliedsnummer);
                if ($andere !== null && $andere->id !== $mitgliedId) {
                    $alt = $alt->mit(['mitgliedsnummer' => $aktuell->mitgliedsnummer]);
                    $hinweise[] = sprintf(
                        'Die Mitgliedsnummer %d hat inzwischen ein anderes Mitglied; die aktuelle Nummer bleibt.',
                        $version->daten['mitgliedsnummer'] ?? 0,
                    );
                }
            }

            $ergebnis = $this->speichern($alt, VersionsAnlass::wiederhergestellt($nr), $userId, $jetzt);

            return new SpeicherErgebnis($ergebnis->mitglied, $ergebnis->neueVersion, $hinweise);
        });
    }

    /**
     * The versions of a member, newest first (the tab "Verlauf").
     *
     * @return list<MitgliedVersion>
     */
    public function versionen(int $mitgliedId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mitglied_version WHERE mitglied_id = ? ORDER BY nr DESC');
        $stmt->execute([$mitgliedId]);

        return array_map(self::alsVersion(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function version(int $mitgliedId, int $nr): ?MitgliedVersion
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mitglied_version WHERE mitglied_id = ? AND nr = ?');
        $stmt->execute([$mitgliedId, $nr]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::alsVersion($zeile) : null;
    }

    /**
     * Deletes a member for good (admin only, 07 section 1): the member, its
     * versions, departments, functions, proofs, annual fees and honours (by
     * the foreign keys). Direct debits keep their own copy (08 section 3).
     * An emptied family is deleted too.
     *
     * Returns the blob ids of the deleted proofs: the caller deletes those
     * files, the database cannot.
     *
     * @return list<int>
     */
    public function loeschen(int $id): array
    {
        return Zeilen::transaktion($this->pdo, function () use ($id): array {
            $stmt = $this->pdo->prepare('SELECT blob_id FROM nachweis WHERE mitglied_id = ? AND blob_id IS NOT NULL');
            $stmt->execute([$id]);
            $blobs = array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));

            $stmt = $this->pdo->prepare('SELECT familie_id FROM mitglied WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $familieId = Zeilen::idOderNull($stmt->fetchColumn() ?: null);

            $this->pdo->prepare('DELETE FROM mitglied WHERE id = ?')->execute([$id]);
            if ($familieId !== null) {
                new FamilieRepository($this->pdo)->loeschenWennLeer($familieId);
            }

            return $blobs;
        });
    }

    /**
     * Deletes ALL members for good - the "Bestand leeren" of the first import
     * (docs/spec/10-import.md section 1): members with their versions,
     * departments, functions, proofs, annual fees and honours (by the foreign
     * keys), and every family. The caller makes sure no annual fee exists.
     *
     * Returns the blob ids of the deleted proofs, as loeschen() does.
     *
     * @return list<int>
     */
    public function alleLoeschen(): array
    {
        return Zeilen::transaktion($this->pdo, function (): array {
            $blobs = array_map(
                intval(...),
                $this->pdo->query('SELECT blob_id FROM nachweis WHERE blob_id IS NOT NULL')?->fetchAll(\PDO::FETCH_COLUMN) ?: [],
            );
            $this->pdo->exec('DELETE FROM mitglied');
            $this->pdo->exec('DELETE FROM familie');

            return $blobs;
        });
    }

    private function einfuegen(Mitglied $mitglied, VersionsAnlass $anlass, ?int $userId, string $zeit): SpeicherErgebnis
    {
        if ($this->findeNachNummer($mitglied->mitgliedsnummer) !== null) {
            throw new MitgliedsnummerVergebenException($mitglied->mitgliedsnummer);
        }

        $this->pdo->prepare(sprintf(
            'INSERT INTO mitglied (%s, version, created_at, updated_at) VALUES (%s, 1, ?, ?)',
            implode(', ', self::SPALTEN),
            Zeilen::platzhalter(count(self::SPALTEN)),
        ))->execute([...self::werte($mitglied), $zeit, $zeit]);
        $id = (int) $this->pdo->lastInsertId();

        $this->zuordnungenSchreiben($id, $mitglied);
        $gespeichert = $this->gespeichert($id);
        $this->versionSchreiben($gespeichert, $anlass, $userId, $zeit);

        return new SpeicherErgebnis($gespeichert, true);
    }

    private function aendern(Mitglied $mitglied, VersionsAnlass $anlass, ?int $userId, string $zeit): SpeicherErgebnis
    {
        $id = (int) $mitglied->id;
        $stmt = $this->pdo->prepare('SELECT version FROM mitglied WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $version = $stmt->fetchColumn();
        if ($version === false) {
            throw new VersionKonfliktException('The member does not exist any more.');
        }
        if ((int) $version !== $mitglied->version) {
            throw new VersionKonfliktException('The member was saved by somebody else in the meantime.');
        }

        $vorher = $this->finde($id);
        assert($vorher !== null);
        if ($vorher->daten() === $mitglied->daten() && $vorher->herkunft === $mitglied->herkunft) {
            return new SpeicherErgebnis($vorher, false);
        }

        if ($mitglied->mitgliedsnummer !== $vorher->mitgliedsnummer) {
            $andere = $this->findeNachNummer($mitglied->mitgliedsnummer);
            if ($andere !== null && $andere->id !== $id) {
                throw new MitgliedsnummerVergebenException($mitglied->mitgliedsnummer);
            }
        }

        // Only the origin changed (an import taking over a manual member):
        // the row changes, but there is no new version - origin is not part
        // of the member's data (Mitglied::daten()).
        $neueVersion = $vorher->daten() !== $mitglied->daten();
        $nr = $neueVersion ? $vorher->version + 1 : $vorher->version;

        $this->pdo->prepare(sprintf(
            'UPDATE mitglied SET %s, version = ?, updated_at = ? WHERE id = ?',
            implode(', ', array_map(static fn(string $spalte): string => $spalte . ' = ?', self::SPALTEN)),
        ))->execute([...self::werte($mitglied), $nr, $zeit, $id]);

        $this->zuordnungenSchreiben($id, $mitglied);
        $gespeichert = $this->gespeichert($id);
        if ($neueVersion) {
            $this->versionSchreiben($gespeichert, $anlass, $userId, $zeit);
        }

        if ($vorher->familieId !== null && $vorher->familieId !== $mitglied->familieId) {
            new FamilieRepository($this->pdo)->loeschenWennLeer($vorher->familieId);
        }

        return new SpeicherErgebnis($gespeichert, $neueVersion);
    }

    /**
     * The member as it is stored now - read back rather than assembled, so
     * the caller gets exactly what the next finde() returns (sorted ids,
     * calendar days without a time).
     */
    private function gespeichert(int $id): Mitglied
    {
        $mitglied = $this->finde($id);
        assert($mitglied !== null);

        return $mitglied;
    }

    /** Replaces the department and function links of a member. */
    private function zuordnungenSchreiben(int $id, Mitglied $mitglied): void
    {
        foreach (['mitglied_abteilung' => ['abteilung_id', $mitglied->abteilungIds], 'mitglied_funktion' => ['funktion_id', $mitglied->funktionIds]] as $tabelle => [$spalte, $ids]) {
            $this->pdo->prepare(sprintf('DELETE FROM %s WHERE mitglied_id = ?', $tabelle))->execute([$id]);
            $einfuegen = $this->pdo->prepare(sprintf('INSERT INTO %s (mitglied_id, %s) VALUES (?, ?)', $tabelle, $spalte));
            foreach (array_unique($ids) as $zielId) {
                $einfuegen->execute([$id, $zielId]);
            }
        }
    }

    /**
     * One version row: Mitglied::daten() plus the names of the moment
     * (MitgliedVersion::bezeichnungen()), so the history stays readable
     * after a rename or delete.
     */
    private function versionSchreiben(Mitglied $mitglied, VersionsAnlass $anlass, ?int $userId, string $zeit): void
    {
        $daten = $mitglied->daten();
        $daten['bezeichnungen'] = [
            'familie' => $mitglied->familieId === null ? null : $this->name('familie', $mitglied->familieId),
            'beitragsart' => $mitglied->beitragsartId === null ? null : $this->name('beitragsart', $mitglied->beitragsartId),
            'abteilungen' => $this->namen('abteilung', $mitglied->abteilungIds),
            'funktionen' => $this->namen('funktion', $mitglied->funktionIds),
        ];

        $this->pdo->prepare(
            'INSERT INTO mitglied_version (mitglied_id, nr, anlass, geaendert_von, geaendert_am, daten) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([$mitglied->id, $mitglied->version, $anlass->text, $userId, $zeit, Zeilen::json($daten)]);
    }

    /** @param 'familie'|'beitragsart' $tabelle */
    private function name(string $tabelle, int $id): ?string
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT name FROM %s WHERE id = ?', $tabelle));
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    /**
     * id => name, as JSON object keys (strings).
     *
     * @param 'abteilung'|'funktion' $tabelle
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function namen(string $tabelle, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id, name FROM %s WHERE id IN (%s) ORDER BY id',
            $tabelle,
            Zeilen::platzhalter(count($ids)),
        ));
        $stmt->execute($ids);
        $namen = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
            $namen[(string) $zeile['id']] = (string) $zeile['name'];
        }

        return $namen;
    }

    /** @param 'familie'|'beitragsart' $tabelle */
    private function gibtEs(string $tabelle, int $id): bool
    {
        return $this->name($tabelle, $id) !== null;
    }

    /**
     * The ids that still exist, sorted.
     *
     * @param 'abteilung'|'funktion' $tabelle
     * @param list<int> $ids
     * @return list<int>
     */
    private function vorhandene(string $tabelle, array $ids): array
    {
        return array_map(intval(...), array_keys($this->namen($tabelle, $ids)));
    }

    /**
     * @param list<mixed> $werte
     */
    private function eines(string $sql, array $werte): ?Mitglied
    {
        return $this->mehrere($sql, $werte)[0] ?? null;
    }

    /**
     * Rows to members, with departments and functions loaded in one query
     * each for the whole list.
     *
     * @param list<mixed> $werte
     * @return list<Mitglied>
     */
    private function mehrere(string $sql, array $werte): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($werte);
        $zeilen = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($zeilen === []) {
            return [];
        }

        $ids = array_map(static fn(array $zeile): int => (int) $zeile['id'], $zeilen);
        $abteilungen = $this->zuordnungen('mitglied_abteilung', 'abteilung_id', $ids);
        $funktionen = $this->zuordnungen('mitglied_funktion', 'funktion_id', $ids);

        return array_map(
            static fn(array $zeile): Mitglied => self::mitglied(
                $zeile,
                $abteilungen[(int) $zeile['id']] ?? [],
                $funktionen[(int) $zeile['id']] ?? [],
            ),
            $zeilen,
        );
    }

    /**
     * @param list<int> $mitgliedIds
     * @return array<int, list<int>> member id => sorted target ids
     */
    private function zuordnungen(string $tabelle, string $spalte, array $mitgliedIds): array
    {
        $ergebnis = [];
        foreach (array_chunk($mitgliedIds, 1000) as $teil) {
            $stmt = $this->pdo->prepare(sprintf(
                'SELECT mitglied_id, %s AS ziel FROM %s WHERE mitglied_id IN (%s) ORDER BY %s',
                $spalte,
                $tabelle,
                Zeilen::platzhalter(count($teil)),
                $spalte,
            ));
            $stmt->execute($teil);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
                $ergebnis[(int) $zeile['mitglied_id']][] = (int) $zeile['ziel'];
            }
        }

        return $ergebnis;
    }

    /**
     * The column values in the order of SPALTEN.
     *
     * @return list<string|int|null>
     */
    private static function werte(Mitglied $m): array
    {
        return [
            $m->mitgliedsnummer, $m->anrede, $m->titel, $m->vorname, $m->nachname, $m->namenszusatz,
            $m->geschlecht?->value, Zeilen::fuerDatum($m->geburtsdatum), $m->strasse, $m->plz, $m->ort, $m->land,
            $m->telefon, $m->handy, $m->email, Zeilen::fuerDatum($m->eintrittsdatum),
            Zeilen::fuerDatum($m->austrittsdatum), $m->austrittsgrund, (int) $m->aktiv, $m->beitragsartId,
            $m->zahlart->value, $m->iban, $m->bic, $m->kontoinhaber, $m->mandatsreferenz,
            Zeilen::fuerDatum($m->mandatsdatum), $m->familieId, $m->familieRolle?->value, $m->lizenznummer,
            $m->bemerkung, $m->herkunft->value,
        ];
    }

    /**
     * @param array<string, mixed> $z
     * @param list<int> $abteilungIds
     * @param list<int> $funktionIds
     */
    private static function mitglied(array $z, array $abteilungIds, array $funktionIds): Mitglied
    {
        return new Mitglied(
            mitgliedsnummer: (int) $z['mitgliedsnummer'],
            nachname: (string) $z['nachname'],
            vorname: (string) $z['vorname'],
            anrede: (string) $z['anrede'],
            titel: (string) $z['titel'],
            namenszusatz: (string) $z['namenszusatz'],
            geschlecht: $z['geschlecht'] === null ? null : Geschlecht::tryFrom((string) $z['geschlecht']),
            geburtsdatum: Zeilen::datum($z['geburtsdatum']),
            strasse: (string) $z['strasse'],
            plz: (string) $z['plz'],
            ort: (string) $z['ort'],
            land: (string) $z['land'],
            telefon: (string) $z['telefon'],
            handy: (string) $z['handy'],
            email: (string) $z['email'],
            eintrittsdatum: Zeilen::datum($z['eintrittsdatum']),
            austrittsdatum: Zeilen::datum($z['austrittsdatum']),
            austrittsgrund: (string) $z['austrittsgrund'],
            aktiv: (bool) $z['aktiv'],
            abteilungIds: $abteilungIds,
            beitragsartId: Zeilen::idOderNull($z['beitragsart_id']),
            zahlart: Zahlart::tryFrom((string) $z['zahlart']) ?? Zahlart::Lastschrift,
            iban: (string) $z['iban'],
            bic: (string) $z['bic'],
            kontoinhaber: (string) $z['kontoinhaber'],
            mandatsreferenz: (string) $z['mandatsreferenz'],
            mandatsdatum: Zeilen::datum($z['mandatsdatum']),
            familieId: Zeilen::idOderNull($z['familie_id']),
            familieRolle: $z['familie_rolle'] === null ? null : FamilienRolle::tryFrom((string) $z['familie_rolle']),
            funktionIds: $funktionIds,
            lizenznummer: (string) $z['lizenznummer'],
            bemerkung: (string) $z['bemerkung'],
            herkunft: Herkunft::tryFrom((string) $z['herkunft']) ?? Herkunft::Manuell,
            id: (int) $z['id'],
            version: (int) $z['version'],
            createdAt: Zeilen::zeitpunkt($z['created_at']),
            updatedAt: Zeilen::zeitpunkt($z['updated_at']),
        );
    }

    /**
     * @param array<string, mixed> $z
     */
    private static function alsVersion(array $z): MitgliedVersion
    {
        return new MitgliedVersion(
            id: (int) $z['id'],
            mitgliedId: (int) $z['mitglied_id'],
            nr: (int) $z['nr'],
            anlass: VersionsAnlass::ausText((string) $z['anlass']),
            geaendertVon: Zeilen::idOderNull($z['geaendert_von']),
            geaendertAm: Zeilen::zeitpunkt($z['geaendert_am']),
            daten: Zeilen::jsonArray($z['daten']),
        );
    }
}
