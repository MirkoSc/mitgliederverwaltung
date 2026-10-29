<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\BlobMeta;
use App\Domain\Herkunft;
use App\Domain\Job;
use App\Domain\JobStatus;
use App\Domain\VersionsAnlass;
use App\Repository\AbteilungRepository;
use App\Repository\FamilieRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\JobRepository;
use App\Repository\MitgliedRepository;
use App\Repository\Zeilen;
use App\Service\Audit\AuditLog;
use App\Service\Storage\BlobService;
use App\Support\FileLogger;

/**
 * The first import (docs/spec/10-import.md section 1): upload the file,
 * preview, import as a step chain, and "Bestand leeren" for dry runs.
 *
 * The uploaded file is kept encrypted as a blob (server key) between the
 * requests and removed when the import is done, cancelled or the stock
 * emptied. The chain's job row (`job`, typ "import") holds only the
 * position and counters, never member data. Phases in `state.phase`:
 * `vorschau` (file uploaded, nothing imported), `import` (chain started),
 * `fertig` (done, the row stays as the record of the last import).
 *
 * Every step is repeatable: a member whose number already exists is skipped,
 * a family is found by its key before it is created, and the position is
 * written only after the step's transaction committed. A step that died
 * halfway is simply done again by the next request.
 */
final readonly class ErstImport
{
    public const string TYP = 'import';

    /** Lines per step (10 section 1); a few hundred ms of work, far below the time limit. */
    public const int SCHRITT = 100;

    /** Lock of one step in seconds; after that a died step may be taken over. */
    private const int SPERRE_SEKUNDEN = 25;

    /** The largest file accepted (a club of several thousand members stays far below). */
    public const int MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private \PDO $pdo,
        private JobRepository $jobs,
        private BlobService $blobs,
        private MitgliedRepository $mitglieder,
        private FamilieRepository $familien,
        private JahresbeitragRepository $beitraege,
        private AuditLog $audit,
        private FileLogger $logger,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static function (Dienste $d): self {
            $pdo = $d->pdo();

            return new self(
                $pdo,
                $d->jobs(),
                BlobService::aus($d),
                new MitgliedRepository($pdo),
                new FamilieRepository($pdo),
                new JahresbeitragRepository($pdo),
                $d->audit(),
                $d->logger,
            );
        });
    }

    /** The newest import job, whatever its state; null if there never was one. */
    public function aktuellerJob(): ?Job
    {
        return $this->jobs->neuesteVomTyp(self::TYP);
    }

    public function bestandIstLeer(): bool
    {
        return $this->mitglieder->anzahl() === 0;
    }

    public function anzahlMitglieder(): int
    {
        return $this->mitglieder->anzahl();
    }

    public function kannLeeren(): bool
    {
        return !$this->beitraege->gibtEs();
    }

    /**
     * Takes the file in and starts the preview: the file is kept, an older
     * unfinished upload is dropped. The file is not checked here - the
     * preview shows what is wrong with it.
     *
     * @throws ImportNichtMoeglich the stock is not empty
     */
    public function hochladen(string $inhalt, string $dateiname, ?int $userId): void
    {
        $this->bestandMussLeerSein();
        $this->verwerfen();

        $blob = $this->blobs->storeString($inhalt, new BlobMeta('text/csv', BlobMeta::bereinigterName($dateiname)));
        $this->jobs->anlegen(self::TYP, $userId, [
            'phase' => 'vorschau',
            'blob_id' => $blob->id,
            'offset' => 0,
            'gesamt' => 0,
            'uebersprungen' => 0,
        ]);
    }

    /**
     * The preview of the uploaded file; null when there is none (or its file
     * is gone).
     */
    public function vorschau(?\DateTimeImmutable $stichtag = null): ?ImportVorschau
    {
        $job = $this->aktuellerJob();
        if ($job === null || ($job->state['phase'] ?? '') !== 'vorschau') {
            return null;
        }
        $datei = $this->datei($job);
        if ($datei === null) {
            return null;
        }
        $stammdaten = ImportStammdaten::aus($this->pdo);
        $namen = [];
        foreach (new AbteilungRepository($this->pdo)->alle() as $abteilung) {
            if ($abteilung->id !== null) {
                $namen[$abteilung->id] = $abteilung->name;
            }
        }

        return ImportVorschau::aus($datei, $stammdaten, $namen, $stichtag ?? new \DateTimeImmutable('today'));
    }

    /**
     * Starts the chain. Checks everything again: the file may have errors,
     * the stock may have been filled since the upload.
     *
     * @throws ImportNichtMoeglich
     */
    public function starten(): void
    {
        $job = $this->aktuellerJob();
        if ($job === null || ($job->state['phase'] ?? '') !== 'vorschau') {
            throw new ImportNichtMoeglich('Es ist keine Datei zum Importieren hochgeladen.');
        }
        $this->bestandMussLeerSein();
        $datei = $this->datei($job);
        if ($datei === null) {
            throw new ImportNichtMoeglich('Die hochgeladene Datei gibt es nicht mehr. Bitte noch einmal hochladen.');
        }
        if (!$datei->istFehlerfrei()) {
            throw new ImportNichtMoeglich('Die Datei enthält Fehler. Bitte die Fehler beheben und die Datei neu hochladen – importiert wird nur eine fehlerfreie Datei.');
        }

        $sperre = $this->jobs->sperren($job->id, self::SPERRE_SEKUNDEN) ?? throw new ImportLaeuftBereits();
        $state = [...$job->state, 'phase' => 'import', 'offset' => 0, 'gesamt' => count($datei->eintraege), 'uebersprungen' => 0];
        if (!$this->jobs->schrittSpeichern($sperre, $state, 0)) {
            throw new ImportLaeuftBereits();
        }
    }

    /**
     * One step of the chain: up to SCHRITT lines. Returns the position for
     * the page: phase, offset, gesamt, uebersprungen and, when done, angelegt
     * and familien.
     *
     * @return array<string, mixed>
     * @throws ImportNichtMoeglich
     * @throws ImportLaeuftBereits
     */
    public function schritt(?string $ip = null): array
    {
        $job = $this->aktuellerJob();
        if ($job === null || !in_array($job->state['phase'] ?? '', ['import', 'fertig'], true)) {
            throw new ImportNichtMoeglich('Es läuft kein Import.');
        }
        if ($job->status === JobStatus::Fertig) {
            return self::stand($job->state);
        }

        $sperre = $this->jobs->sperren($job->id, self::SPERRE_SEKUNDEN) ?? throw new ImportLaeuftBereits();
        // The lock is ours: what was written before it is the true position.
        $job = $this->jobs->finde($job->id) ?? throw new ImportNichtMoeglich('Es läuft kein Import.');
        $state = $job->state;

        try {
            $datei = $this->datei($job) ?? throw new ImportNichtMoeglich('Die hochgeladene Datei gibt es nicht mehr. Bitte den Import abbrechen und die Datei noch einmal hochladen.');
            if (!$datei->istFehlerfrei()) {
                throw new ImportNichtMoeglich('Die Stammdaten (Abteilungen, Beitragsarten, Funktionen) haben sich seit der Vorschau geändert. Bitte den Import abbrechen und neu beginnen.');
            }

            $offset = (int) ($state['offset'] ?? 0);
            $uebersprungen = (int) ($state['uebersprungen'] ?? 0);
            $angelegt = (int) ($state['angelegt'] ?? 0);
            $teil = array_slice($datei->eintraege, $offset, self::SCHRITT);
            [$neu, $uebersprungenImSchritt] = $this->importieren($teil, $datei, $job->userId);
            $angelegt += $neu;
            $uebersprungen += $uebersprungenImSchritt;
            $offset += count($teil);
            $gesamt = count($datei->eintraege);

            $state = [...$state, 'offset' => $offset, 'gesamt' => $gesamt, 'uebersprungen' => $uebersprungen, 'angelegt' => $angelegt];
            if ($offset < $gesamt) {
                if (!$this->jobs->schrittSpeichern($sperre, $state, intdiv(100 * $offset, max(1, $gesamt)))) {
                    throw new ImportLaeuftBereits();
                }

                return self::stand($state);
            }

            $state = [...$state, 'phase' => 'fertig', 'familien' => count($datei->familien)];
            if (!$this->jobs->abschliessen($sperre, $state)) {
                throw new ImportLaeuftBereits();
            }
        } catch (ImportLaeuftBereits $e) {
            throw $e;
        } catch (\Throwable $fehler) {
            // Let go of the lock and keep the position: the next request repeats the step.
            $this->jobs->schrittSpeichern($sperre, $job->state, $job->progress);

            throw $fehler;
        }

        $this->dateiLoeschen((int) ($state['blob_id'] ?? 0));
        $this->audit->protokollieren(
            AuditAktion::ImportDurchgefuehrt,
            $job->userId,
            'import',
            $job->id,
            [
                'mitglieder' => (int) $state['angelegt'],
                'familien' => (int) $state['familien'],
                'uebersprungen' => (int) $state['uebersprungen'],
            ],
            $ip,
        );

        return self::stand($state);
    }

    /**
     * Drops the upload (or a started chain) and its file. What a started
     * chain already imported stays; "Bestand leeren" takes it away.
     */
    public function verwerfen(): void
    {
        $job = $this->aktuellerJob();
        if ($job === null || $job->status === JobStatus::Fertig) {
            return;
        }
        $this->dateiLoeschen((int) ($job->state['blob_id'] ?? 0));
        $this->jobs->loeschen($job->id);
    }

    /**
     * "Bestand leeren" for dry runs: all members with everything attached,
     * all families, the upload and the record of earlier imports. Only while
     * no annual fee exists (10 section 1).
     *
     * @return int how many members were deleted
     * @throws ImportNichtMoeglich
     */
    public function leeren(?int $userId, ?string $ip = null): int
    {
        if (!$this->kannLeeren()) {
            throw new ImportNichtMoeglich('Es gibt schon Jahresbeiträge. Der Bestand kann nicht mehr geleert werden.');
        }

        $anzahl = $this->mitglieder->anzahl();
        foreach ($this->mitglieder->alleLoeschen() as $blobId) {
            $this->dateiLoeschen($blobId);
        }
        while (($job = $this->aktuellerJob()) !== null) {
            $this->dateiLoeschen((int) ($job->state['blob_id'] ?? 0));
            $this->jobs->loeschen($job->id);
        }
        $this->audit->protokollieren(AuditAktion::BestandGeleert, $userId, 'mitglied', null, ['mitglieder' => $anzahl], $ip);

        return $anzahl;
    }

    /**
     * Saves the lines of one step; returns how many of them are imported
     * members (new, or done by an earlier try of this step) and how many
     * were skipped because the number belonged to somebody else already.
     * Counting the lines instead of the table keeps members entered by hand
     * meanwhile out of the figure.
     *
     * @param list<ImportEintrag> $teil
     * @return array{int, int} imported, skipped
     */
    private function importieren(array $teil, ImportDatei $datei, ?int $userId): array
    {
        return Zeilen::transaktion($this->pdo, function () use ($teil, $datei, $userId): array {
            $angelegt = 0;
            $uebersprungen = 0;
            $familienIds = [];
            foreach ($teil as $eintrag) {
                $vorhanden = $this->mitglieder->findeNachNummer($eintrag->mitglied->mitgliedsnummer);
                if ($vorhanden !== null) {
                    // Done by an earlier try of this step - or a member entered by hand meanwhile.
                    if ($vorhanden->herkunft === Herkunft::Import) {
                        $angelegt++;
                    } else {
                        $uebersprungen++;
                    }
                    continue;
                }

                $mitglied = $eintrag->mitglied;
                if ($eintrag->familie !== null) {
                    $familienIds[$eintrag->familie] ??= $this->familienId($eintrag->familie, $datei->familien[$eintrag->familie] ?? 'Familie');
                    $mitglied = $mitglied->mit(['familieId' => $familienIds[$eintrag->familie]]);
                }
                $this->mitglieder->speichern($mitglied, VersionsAnlass::importiert(), $userId);
                $angelegt++;
            }

            return [$angelegt, $uebersprungen];
        });
    }

    private function familienId(string $schluessel, string $name): int
    {
        $familie = $this->familien->findeNachImportSchluessel($schluessel) ?? $this->familien->anlegen($name, $schluessel);

        return $familie->id;
    }

    private function bestandMussLeerSein(): void
    {
        if (!$this->bestandIstLeer()) {
            throw new ImportNichtMoeglich('Der Mitgliederbestand ist nicht leer. Der Erstimport geht nur in einen leeren Bestand.');
        }
    }

    /** The uploaded file read; null when its file is gone. */
    private function datei(Job $job): ?ImportDatei
    {
        $blob = $this->blobs->find((int) ($job->state['blob_id'] ?? 0));
        if ($blob === null || !$blob->isComplete()) {
            return null;
        }
        try {
            $inhalt = '';
            foreach ($this->blobs->openRead($blob) as $stueck) {
                $inhalt .= $stueck;
            }
        } catch (\Throwable $fehler) {
            $this->logger->append('Import file could not be read: ' . $fehler::class);

            return null;
        }

        return ImportLeser::lesen($inhalt, ImportStammdaten::aus($this->pdo));
    }

    private function dateiLoeschen(int $blobId): void
    {
        if ($blobId <= 0) {
            return;
        }
        try {
            $blob = $this->blobs->find($blobId);
            if ($blob !== null) {
                $this->blobs->delete($blob);
            }
        } catch (\Throwable $fehler) {
            // An orphaned file is encrypted and harmless; the chain goes on.
            $this->logger->append(sprintf('File %d could not be removed: %s', $blobId, $fehler::class));
        }
    }

    /**
     * What the page's script needs.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function stand(array $state): array
    {
        return [
            'phase' => (string) ($state['phase'] ?? ''),
            'fertig' => ($state['phase'] ?? '') === 'fertig',
            'offset' => (int) ($state['offset'] ?? 0),
            'gesamt' => (int) ($state['gesamt'] ?? 0),
            'uebersprungen' => (int) ($state['uebersprungen'] ?? 0),
            'angelegt' => (int) ($state['angelegt'] ?? 0),
            'familien' => (int) ($state['familien'] ?? 0),
        ];
    }
}
