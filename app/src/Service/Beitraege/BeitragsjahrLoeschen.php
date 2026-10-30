<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Blob;
use App\Domain\BeitragNichtAenderbar;
use App\Domain\Einzug;
use App\Domain\EinzugPosten;
use App\Repository\BlobRepository;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\Zeilen;
use App\Service\Audit\AuditLog;
use App\Service\Storage\BlobService;

/**
 * "Beiträge <Jahr> löschen" (docs/spec/08-beitraege.md section 2a, #90):
 * every fee of a year with its status and notes, and the direct debits of
 * that year with their stored files - in any state, also when paid or
 * already collected. Afterwards the year can be created again. Admin only -
 * the routes check BeitraegeVerwalten.
 *
 * A run belongs to a year when every fee it names (its copy in `daten` and
 * the fees pointing to it) is of that year. A run that also holds another
 * year stays: the other year's fees still point to it, and it is the
 * archive of a file that went to the bank.
 */
final readonly class BeitragsjahrLoeschen
{
    /** What has to be typed to delete, case does not matter. */
    public const string BESTAETIGUNG = 'LÖSCHEN';

    public function __construct(
        private \PDO $pdo,
        private JahresbeitragRepository $beitraege,
        private EinzugRepository $einzuege,
        private BlobRepository $blobZeilen,
        private BlobService $blobs,
        private AuditLog $audit,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->pdo(),
            new JahresbeitragRepository($d->pdo()),
            new EinzugRepository($d->pdo()),
            BlobRepository::aus($d),
            BlobService::aus($d),
            $d->audit(),
        ));
    }

    /** Whether the typed word is the confirmation - trimmed, any case. */
    public static function bestaetigt(string $eingabe): bool
    {
        return mb_strtoupper(trim($eingabe)) === self::BESTAETIGUNG;
    }

    /** What deleting the year would take away, for the question before it. */
    public function umfang(int $jahr): BeitragsjahrUmfang
    {
        return $this->ermitteln($jahr, false);
    }

    /**
     * Deletes the year in one transaction: the fees, then the year's runs
     * and the rows of their files. The encrypted files on disk go after the
     * commit - a rollback must not leave a run without its file.
     *
     * @throws BeitragNichtAenderbar wrong confirmation word, or nothing to delete
     */
    public function loeschen(int $jahr, string $bestaetigung, ?int $von, ?string $ip = null): BeitragsjahrUmfang
    {
        if (!self::bestaetigt($bestaetigung)) {
            throw new BeitragNichtAenderbar(sprintf('Gelöscht wird erst, wenn im Feld „%s“ steht. Es wurde nichts gelöscht.', self::BESTAETIGUNG));
        }

        /** @var list<Blob> $dateien */
        $dateien = [];
        $umfang = Zeilen::transaktion($this->pdo, function () use ($jahr, &$dateien): BeitragsjahrUmfang {
            $umfang = $this->ermitteln($jahr, true);
            if ($umfang->leer()) {
                throw new BeitragNichtAenderbar(sprintf('Für %d gibt es keine Beiträge und keine Einzüge, es wurde nichts gelöscht.', $jahr));
            }

            $this->beitraege->loescheJahr($jahr);
            foreach ($umfang->einzuege as $einzug) {
                $this->einzuege->loeschen((int) $einzug->id);
                $blob = $einzug->dateiBlobId === null ? null : $this->blobs->find($einzug->dateiBlobId);
                if ($blob !== null) {
                    // The row (and in the db backend its chunks) goes with the transaction.
                    $this->blobZeilen->delete($blob->id);
                    $dateien[] = $blob;
                }
            }

            return $umfang;
        });

        foreach ($dateien as $blob) {
            try {
                $this->blobs->delete($blob);
            } catch (\Throwable) {
                // The row is gone; an orphaned ciphertext is harmless.
            }
        }

        $this->audit->protokollieren(AuditAktion::BeitraegeJahrGeloescht, $von, 'beitragsjahr', $jahr, $umfang->protokoll(), $ip);

        return $umfang;
    }

    private function ermitteln(int $jahr, bool $zumAendern): BeitragsjahrUmfang
    {
        $zahlen = $this->beitraege->umfangImJahr($jahr, $zumAendern);
        $jahreDerBeitraege = $this->beitraege->jahreJeEinzug();

        $einzuege = [];
        $gemischt = [];
        foreach ($this->einzuege->alle() as $einzug) {
            $jahre = array_map(static fn(EinzugPosten $p): int => $p->jahr, $einzug->posten());
            $jahre = array_values(array_unique([...$jahre, ...($jahreDerBeitraege[(int) $einzug->id] ?? [])]));
            if (!in_array($jahr, $jahre, true)) {
                continue;
            }
            if ($jahre === [$jahr]) {
                $einzuege[] = $einzug;
            } else {
                $gemischt[] = $einzug;
            }
        }

        return new BeitragsjahrUmfang($jahr, $zahlen['anzahl'], $zahlen['bezahlt'], $zahlen['summe_cent'], $einzuege, $gemischt);
    }
}
