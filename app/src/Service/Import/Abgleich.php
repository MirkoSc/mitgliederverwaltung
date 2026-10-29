<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Herkunft;
use App\Domain\Job;
use App\Domain\JobStatus;
use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FamilieRepository;
use App\Repository\FunktionRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\JobRepository;
use App\Repository\MitgliedRepository;
use App\Repository\Zeilen;
use App\Service\Audit\AuditLog;
use App\Service\Storage\BlobService;

/**
 * The reconciliation with a later file of the old software
 * (docs/spec/10-import.md section 4): upload import.csv into a stock that is
 * not empty, see the merge view (Neu / Fehlt in der Datei / Geändert), tick
 * what to take over, and take it over as a step chain.
 *
 * Like the first import, the file waits encrypted as a blob and the job row
 * (`job`, typ "abgleich") holds only numbers: the phase, the ticked member
 * numbers as a list of operations, the position and the counters. Phases in
 * `state.phase`: `vorschau` (merge view), `abgleich` (chain started),
 * `fertig` (done; the row stays as the record of the last reconciliation).
 *
 * Every change writes a version with the reason `abgleich` (07 section 7),
 * so each reconciliation can be traced and undone per member. Every
 * operation is repeatable - a step that died halfway is simply done again:
 * a new member whose number exists is not created twice, a change or a
 * departure that is already done changes nothing (no version without a
 * change), a deleted member is gone.
 */
final readonly class Abgleich
{
    public const string TYP = 'abgleich';

    /** Operations per step; each is a few queries. */
    public const int SCHRITT = 100;

    private const int SPERRE_SEKUNDEN = 25;

    /** The counters of a run; the summary and the audit entry show them. */
    public const array ZAEHLER = ['neu', 'geaendert', 'ausgetreten', 'geloescht', 'uebersprungen'];

    /** Operation codes in `state.ops`, in the order they are done. */
    private const string NEU = 'n';
    private const string AENDERN = 'a';
    private const string AUSTRITT = 'x';
    private const string LOESCHEN = 'l';

    public function __construct(
        private \PDO $pdo,
        private JobRepository $jobs,
        private ImportDateiAblage $ablage,
        private MitgliedRepository $mitglieder,
        private FamilieRepository $familien,
        private JahresbeitragRepository $beitraege,
        private AuditLog $audit,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static function (Dienste $d): self {
            $pdo = $d->pdo();

            return new self(
                $pdo,
                $d->jobs(),
                new ImportDateiAblage($pdo, BlobService::aus($d), $d->logger),
                new MitgliedRepository($pdo),
                new FamilieRepository($pdo),
                new JahresbeitragRepository($pdo),
                $d->audit(),
            );
        });
    }

    /** The newest reconciliation, whatever its state; null if there never was one. */
    public function aktuellerJob(): ?Job
    {
        return $this->jobs->neuesteVomTyp(self::TYP);
    }

    /**
     * Takes a file in for the merge view. Older reconciliations - also an
     * unfinished one - are dropped with their files; the audit log keeps
     * what they did.
     *
     * @throws ImportNichtMoeglich the stock is empty: that is a first import
     */
    public function hochladen(string $inhalt, string $dateiname, ?int $userId): void
    {
        if ($this->mitglieder->anzahl() === 0) {
            throw new ImportNichtMoeglich('Der Mitgliederbestand ist leer. Bitte zuerst den Erstimport durchführen.');
        }
        $this->allesVerwerfen();

        $this->jobs->anlegen(self::TYP, $userId, [
            'phase' => 'vorschau',
            'blob_id' => $this->ablage->speichern($inhalt, $dateiname),
        ]);
    }

    /** The merge view of the uploaded file; null when there is none (or its file is gone). */
    public function vorschau(): ?AbgleichVorschau
    {
        $job = $this->aktuellerJob();
        if ($job === null || ($job->state['phase'] ?? '') !== 'vorschau') {
            return null;
        }
        $datei = $this->datei($job);
        if ($datei === null) {
            return null;
        }

        return new AbgleichVorschau(
            $datei,
            $datei->istFehlerfrei() ? $this->vergleichen($datei) : null,
            $this->felder($datei),
        );
    }

    /**
     * Starts the chain with what was ticked. The sorting is done again, so
     * only numbers that still belong to their section count - anything else
     * sent along is ignored ("nur angehakte Zeilen werden umgesetzt").
     *
     * @param array{neu?: list<int>, aendern?: list<int>, austritt?: list<int>, loeschen?: list<int>} $auswahl member numbers per section
     * @throws ImportNichtMoeglich
     * @throws ImportLaeuftBereits
     */
    public function starten(array $auswahl, ?\DateTimeImmutable $austrittsdatum): void
    {
        $job = $this->aktuellerJob();
        if ($job === null || ($job->state['phase'] ?? '') !== 'vorschau') {
            throw new ImportNichtMoeglich('Es ist keine Datei zum Abgleichen hochgeladen.');
        }
        $datei = $this->datei($job) ?? throw new ImportNichtMoeglich('Die hochgeladene Datei gibt es nicht mehr. Bitte noch einmal hochladen.');
        if (!$datei->istFehlerfrei()) {
            throw new ImportNichtMoeglich('Die Datei enthält Fehler. Bitte die Fehler beheben und die Datei neu hochladen.');
        }
        $vergleich = $this->vergleichen($datei);

        $neu = self::gewaehlt($auswahl['neu'] ?? [], array_map(static fn(ImportEintrag $e): int => $e->mitglied->mitgliedsnummer, $vergleich->neu));
        $aendern = self::gewaehlt($auswahl['aendern'] ?? [], array_map(static fn(AbgleichAenderung $a): int => $a->vorher->mitgliedsnummer, $vergleich->geaendert));
        $fehlt = [];
        foreach ($vergleich->fehlt as $zeile) {
            $fehlt[$zeile->mitglied->mitgliedsnummer] = $zeile;
        }
        $loeschen = self::gewaehlt($auswahl['loeschen'] ?? [], array_keys($fehlt));
        foreach ($loeschen as $nummer) {
            if (!$fehlt[$nummer]->kannLoeschen) {
                throw new ImportNichtMoeglich(sprintf(
                    'Mitglied Nr. %d hat Jahresbeiträge und kann deshalb nicht ganz gelöscht werden – nur austreten lassen.',
                    $nummer,
                ));
            }
        }
        $austritt = array_values(array_diff(self::gewaehlt($auswahl['austritt'] ?? [], array_keys($fehlt)), $loeschen));

        if ($neu === [] && $aendern === [] && $austritt === [] && $loeschen === []) {
            throw new ImportNichtMoeglich('Es ist nichts angehakt. Bitte die Zeilen anhaken, die übernommen werden sollen.');
        }
        if ($austritt !== [] && $austrittsdatum === null) {
            throw new ImportNichtMoeglich('Bitte ein gültiges Austrittsdatum angeben.');
        }

        $ops = [
            ...array_map(static fn(int $n): array => [self::NEU, $n], $neu),
            ...array_map(static fn(int $n): array => [self::AENDERN, $n], $aendern),
            ...array_map(static fn(int $n): array => [self::AUSTRITT, $n], $austritt),
            ...array_map(static fn(int $n): array => [self::LOESCHEN, $n], $loeschen),
        ];

        $sperre = $this->jobs->sperren($job->id, self::SPERRE_SEKUNDEN) ?? throw new ImportLaeuftBereits();
        // The lock is ours: a second start (double click, second tab) may have been first.
        $frisch = $this->jobs->finde($job->id);
        if ($frisch === null || ($frisch->state['phase'] ?? '') !== 'vorschau') {
            if ($frisch !== null) {
                $this->jobs->schrittSpeichern($sperre, $frisch->state, $frisch->progress);
            }

            throw new ImportLaeuftBereits();
        }
        $state = [
            ...$job->state,
            'phase' => 'abgleich',
            'ops' => $ops,
            'austrittsdatum' => $austrittsdatum?->format('Y-m-d'),
            'offset' => 0,
            'gesamt' => count($ops),
            ...array_fill_keys(self::ZAEHLER, 0),
        ];
        if (!$this->jobs->schrittSpeichern($sperre, $state, 0)) {
            throw new ImportLaeuftBereits();
        }
    }

    /**
     * One step of the chain: up to SCHRITT operations in one transaction.
     * Returns the position for the page: phase, fertig, offset, gesamt and
     * the counters.
     *
     * @return array<string, mixed>
     * @throws ImportNichtMoeglich
     * @throws ImportLaeuftBereits
     */
    public function schritt(?string $ip = null): array
    {
        $job = $this->aktuellerJob();
        if ($job === null || !in_array($job->state['phase'] ?? '', ['abgleich', 'fertig'], true)) {
            throw new ImportNichtMoeglich('Es läuft kein Abgleich.');
        }
        if ($job->status === JobStatus::Fertig) {
            return self::stand($job->state);
        }

        $sperre = $this->jobs->sperren($job->id, self::SPERRE_SEKUNDEN) ?? throw new ImportLaeuftBereits();
        $job = $this->jobs->finde($job->id) ?? throw new ImportNichtMoeglich('Es läuft kein Abgleich.');
        $state = $job->state;

        try {
            $datei = $this->datei($job) ?? throw new ImportNichtMoeglich('Die hochgeladene Datei gibt es nicht mehr. Bitte den Abgleich abbrechen und die Datei noch einmal hochladen.');
            if (!$datei->istFehlerfrei()) {
                throw new ImportNichtMoeglich('Die Stammdaten (Abteilungen, Beitragsarten, Funktionen) haben sich seit der Vorschau geändert. Bitte den Abgleich abbrechen und neu beginnen.');
            }

            /** @var list<array{string, int}> $ops */
            $ops = is_array($state['ops'] ?? null) ? $state['ops'] : [];
            $offset = (int) ($state['offset'] ?? 0);
            $teil = array_slice($ops, $offset, self::SCHRITT);
            $datum = Mitglied::datum(is_string($state['austrittsdatum'] ?? null) ? $state['austrittsdatum'] : null);
            [$zaehler, $dateien] = $this->ausfuehren($teil, $datei, $datum, $job->userId, $ip);
            // The deletions are committed: their files go now, before the
            // position is saved - a repeated step would not find them again.
            foreach ($dateien as $blobId) {
                $this->ablage->loeschen($blobId);
            }
            foreach ($zaehler as $name => $anzahl) {
                $state[$name] = (int) ($state[$name] ?? 0) + $anzahl;
            }
            $offset += count($teil);
            $state = [...$state, 'offset' => $offset, 'gesamt' => count($ops)];

            if ($offset < count($ops)) {
                if (!$this->jobs->schrittSpeichern($sperre, $state, intdiv(100 * $offset, max(1, count($ops))))) {
                    throw new ImportLaeuftBereits();
                }
            } else {
                // The list of numbers has done its work; the record keeps the counters.
                $state = [...$state, 'phase' => 'fertig', 'ops' => []];
                if (!$this->jobs->abschliessen($sperre, $state)) {
                    throw new ImportLaeuftBereits();
                }
            }
        } catch (ImportLaeuftBereits $e) {
            throw $e;
        } catch (\Throwable $fehler) {
            // Let go of the lock and keep the position: the next request repeats the step.
            $this->jobs->schrittSpeichern($sperre, $job->state, $job->progress);

            throw $fehler;
        }

        if (($state['phase'] ?? '') !== 'fertig') {
            return self::stand($state);
        }
        $this->ablage->loeschen((int) ($state['blob_id'] ?? 0));
        $this->protokollieren($job, $state, $ip);

        // Only the request that finished the chain says so; the summary is shown once.
        return [...self::stand($state), 'abgeschlossen' => true];
    }

    /**
     * Drops the upload (or a started chain) and its file. What a started
     * chain already did stays, and the audit log records it as a
     * reconciliation that was cancelled.
     */
    public function verwerfen(?string $ip = null): void
    {
        $job = $this->aktuellerJob();
        if ($job === null || $job->status === JobStatus::Fertig) {
            return;
        }
        $this->entfernen($job, $ip);
    }

    /** Removes every reconciliation, finished ones included, with its file. */
    private function allesVerwerfen(): void
    {
        while (($job = $this->aktuellerJob()) !== null) {
            $this->entfernen($job, null);
        }
    }

    private function entfernen(Job $job, ?string $ip): void
    {
        if ($job->status !== JobStatus::Fertig && ($job->state['phase'] ?? '') === 'abgleich' && (int) ($job->state['offset'] ?? 0) > 0) {
            $this->protokollieren($job, [...$job->state, 'abgebrochen' => true], $ip);
        }
        $this->ablage->loeschen((int) ($job->state['blob_id'] ?? 0));
        $this->jobs->loeschen($job->id);
    }

    /**
     * The audit entry of a reconciliation: numbers only.
     *
     * @param array<string, mixed> $state
     */
    private function protokollieren(Job $job, array $state, ?string $ip): void
    {
        $details = [];
        foreach (self::ZAEHLER as $name) {
            $details[$name] = (int) ($state[$name] ?? 0);
        }
        if (($state['abgebrochen'] ?? false) === true) {
            $details['abgebrochen'] = true;
        }
        $this->audit->protokollieren(AuditAktion::AbgleichDurchgefuehrt, $job->userId, 'import', $job->id, $details, $ip);
    }

    /**
     * Carries out the operations of one step in one transaction.
     *
     * @param list<array{string, int}> $teil
     * @return array{array<string, int>, list<int>} counters; the proof files of deleted members
     */
    private function ausfuehren(array $teil, ImportDatei $datei, ?\DateTimeImmutable $austrittsdatum, ?int $userId, ?string $ip): array
    {
        $eintraege = [];
        foreach ($datei->eintraege as $eintrag) {
            $eintraege[$eintrag->mitglied->mitgliedsnummer] = $eintrag;
        }

        return Zeilen::transaktion($this->pdo, function () use ($teil, $eintraege, $datei, $austrittsdatum, $userId, $ip): array {
            $zaehler = array_fill_keys(self::ZAEHLER, 0);
            $geloescht = [];
            $anlass = VersionsAnlass::abgleich();

            foreach ($teil as $op) {
                [$art, $nummer] = [(string) ($op[0] ?? ''), (int) ($op[1] ?? 0)];
                $vorhanden = $this->mitglieder->findeNachNummer($nummer);
                $eintrag = $eintraege[$nummer] ?? null;

                switch ($art) {
                    case self::NEU:
                        if ($eintrag === null || ($vorhanden !== null && $vorhanden->herkunft !== Herkunft::Import)) {
                            // Entered by hand meanwhile: that member stays as it is.
                            $zaehler['uebersprungen']++;
                        } elseif ($vorhanden === null) {
                            $mitglied = $eintrag->mitglied->mit(['familieId' => $this->familienId($eintrag, $datei)]);
                            $this->mitglieder->speichern($mitglied, $anlass, $userId);
                            $zaehler['neu']++;
                        } else {
                            // Done by an earlier try of this step.
                            $zaehler['neu']++;
                        }
                        break;

                    case self::AENDERN:
                        if ($eintrag === null || $vorhanden === null) {
                            $zaehler['uebersprungen']++;
                            break;
                        }
                        $ziel = Mitglied::ausDaten(AbgleichVergleich::zielDaten($eintrag, $this->familienId($eintrag, $datei), $vorhanden, $datei->spalten), $vorhanden);
                        $this->mitglieder->speichern($ziel, $anlass, $userId);
                        $zaehler['geaendert']++;
                        break;

                    case self::LOESCHEN:
                        if ($vorhanden === null) {
                            // Deleted by an earlier try of this step.
                            $zaehler['geloescht']++;
                            break;
                        }
                        if (!$this->beitraege->hatBeitrag((int) $vorhanden->id)) {
                            $geloescht = [...$geloescht, ...$this->mitglieder->loeschen((int) $vorhanden->id)];
                            // In the same transaction as the deletion, so neither goes without the other.
                            $this->audit->protokollieren(
                                AuditAktion::MitgliedGeloescht,
                                $userId,
                                'mitglied',
                                (int) $vorhanden->id,
                                ['mitgliedsnummer' => $nummer, 'anlass' => 'abgleich'],
                                $ip,
                            );
                            $zaehler['geloescht']++;
                            break;
                        }
                        // An annual fee appeared since the start: the member only leaves.
                        $austrittsdatum ??= new \DateTimeImmutable('today');
                        // no break
                    case self::AUSTRITT:
                        if ($vorhanden === null || $austrittsdatum === null) {
                            $zaehler['uebersprungen']++;
                            break;
                        }
                        if ($vorhanden->austrittsdatum === null) {
                            $this->mitglieder->speichern($vorhanden->mit(['austrittsdatum' => $austrittsdatum]), $anlass, $userId);
                        }
                        $zaehler['ausgetreten']++;
                        break;

                    default:
                        $zaehler['uebersprungen']++;
                }
            }

            return [$zaehler, $geloescht];
        });
    }

    /** The family of a line: none, found by its key, or created now. */
    private function familienId(ImportEintrag $eintrag, ImportDatei $datei): ?int
    {
        if ($eintrag->familie === null) {
            return null;
        }
        $familie = $this->familien->findeNachImportSchluessel($eintrag->familie)
            ?? $this->familien->anlegen($datei->familien[$eintrag->familie] ?? 'Familie', $eintrag->familie);

        return $familie->id;
    }

    private function vergleichen(ImportDatei $datei): AbgleichVergleich
    {
        $familien = [];
        foreach (array_keys($datei->familien) as $schluessel) {
            $familie = $this->familien->findeNachImportSchluessel((string) $schluessel);
            if ($familie !== null) {
                $familien[(string) $schluessel] = $familie->id;
            }
        }

        return AbgleichVergleich::aus($datei, $this->mitglieder->alle(true), $familien, $this->beitraege->mitgliederMitBeitrag());
    }

    private function felder(ImportDatei $datei): AbgleichFelder
    {
        $namen = static function (array $eintraege): array {
            $namen = [];
            foreach ($eintraege as $eintrag) {
                if ($eintrag->id !== null) {
                    $namen[$eintrag->id] = $eintrag->name;
                }
            }

            return $namen;
        };
        $familien = [];
        foreach ($this->familien->suche('', PHP_INT_MAX) as $familie) {
            $familien[$familie->id] = $familie->name;
        }
        $neueFamilien = [];
        foreach ($datei->familien as $schluessel => $name) {
            $neueFamilien[(string) $schluessel] = $name;
        }

        return new AbgleichFelder(
            $namen(new AbteilungRepository($this->pdo)->alle()),
            $namen(new BeitragsartRepository($this->pdo)->alle()),
            $namen(new FunktionRepository($this->pdo)->alle()),
            $familien,
            $neueFamilien,
        );
    }

    /** The uploaded file read; null when its file is gone. */
    private function datei(Job $job): ?ImportDatei
    {
        return $this->ablage->lesen((int) ($job->state['blob_id'] ?? 0));
    }

    /**
     * The ticked numbers that belong to the section, in the section's order.
     *
     * @param list<int> $gewaehlt
     * @param list<int> $erlaubt
     * @return list<int>
     */
    private static function gewaehlt(array $gewaehlt, array $erlaubt): array
    {
        return array_values(array_intersect($erlaubt, array_map(intval(...), $gewaehlt)));
    }

    /**
     * What the page's script needs.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function stand(array $state): array
    {
        $stand = [
            'phase' => (string) ($state['phase'] ?? ''),
            'fertig' => ($state['phase'] ?? '') === 'fertig',
            'offset' => (int) ($state['offset'] ?? 0),
            'gesamt' => (int) ($state['gesamt'] ?? 0),
        ];
        foreach (self::ZAEHLER as $name) {
            $stand[$name] = (int) ($state[$name] ?? 0);
        }

        return $stand;
    }
}
