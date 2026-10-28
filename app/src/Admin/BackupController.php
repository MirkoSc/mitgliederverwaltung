<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\StreamResponse;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupLaeuftBereits;
use App\Service\Backup\BackupService;
use App\Service\Storage\BlobCipher;
use App\View\Area;
use App\View\FlashArt;

/**
 * The routes of the settings section Backups (docs/spec/04-installation-
 * und-update.md section 6): start the step chain, one step per request
 * (driven by public/js/backups.js), cancel it, and download a finished
 * backup as a stream.
 *
 * A backup is encrypted with the server key, so the download is harmless
 * without config.php - which is why a plain GET with the admin right is
 * enough, unlike Vereinsbelege's backups with config.php inside.
 */
final readonly class BackupController
{
    public const string ZIEL = '/admin#backups';

    /** Bytes per piece of the download; the time limit restarts after each. */
    private const int BLOCK = 1024 * 1024;

    public function __construct(
        private Dienste $dienste,
        private BackupService $backups,
        private BackupErstellung $erstellung,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, BackupService::aus($dienste), BackupErstellung::aus($dienste));
    }

    public function starten(): ResponseInterface
    {
        try {
            $stand = $this->erstellung->starten();
        } catch (BackupLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json(self::antwort($stand));
    }

    /** One step of the chain (CLAUDE.md section 1: no long request). */
    public function schritt(): ResponseInterface
    {
        try {
            $stand = $this->erstellung->schritt();
        } catch (BackupLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            // Only the class goes to the log; a database message may quote
            // row data (CLAUDE.md section 4). Our own messages say what to do.
            $this->dienste->logger->append('Backup step failed: ' . $e::class);
            $meldung = $e instanceof \PDOException || !$e instanceof \RuntimeException
                ? 'Der Schritt ist fehlgeschlagen. Bitte noch einmal versuchen oder das Backup abbrechen.'
                : $e->getMessage();

            return Response::json(['fehler' => $meldung], 500);
        }

        if ($stand['gerade_fertig'] === true) {
            $this->dienste->session->flash('Das Backup ist erstellt.');
        }

        return Response::json(self::antwort($stand));
    }

    public function abbrechen(): ResponseInterface
    {
        try {
            $this->erstellung->abbrechen();
            $this->dienste->session->flash('Die Backup-Erstellung ist abgebrochen.');
        } catch (BackupLaeuftBereits) {
            $this->dienste->session->flash('Gerade läuft ein Schritt. Bitte gleich noch einmal abbrechen.', FlashArt::Fehler);
        }

        return Response::redirect(self::ZIEL);
    }

    /**
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): ResponseInterface
    {
        $name = $params['name'] ?? '';
        $pfad = $this->backups->path($name);
        if ($pfad === null) {
            return Response::html($this->dienste->view->render('fehler', [
                'title' => 'Backup nicht gefunden',
                'meldung' => 'Dieses Backup gibt es nicht mehr. Es werden nur die letzten ' . BackupService::KEEP . ' aufbewahrt.',
            ], Area::Admin), 404);
        }
        // The session lock would block the admin's other tabs for the whole
        // download.
        $this->dienste->session->schliessen();

        return new StreamResponse(self::stuecke($pfad), [
            'Content-Type' => 'application/octet-stream',
            // $name passed BackupService::path(): nothing that needs quoting.
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) filesize($pfad),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return \Generator<int, string>
     */
    private static function stuecke(string $pfad): \Generator
    {
        $datei = fopen($pfad, 'rb');
        if ($datei === false) {
            throw new \RuntimeException('Das Backup kann nicht gelesen werden.');
        }
        try {
            while (!feof($datei)) {
                $stueck = fread($datei, self::BLOCK);
                if ($stueck === false) {
                    return;
                }
                yield $stueck;
            }
        } finally {
            fclose($datei);
        }
    }

    /**
     * What the page's script needs - no paths, nothing about the club.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    public static function antwort(array $stand): array
    {
        $verschluesselung = is_array($stand['verschluesselung'] ?? null) ? $stand['verschluesselung'] : null;

        return [
            'phase' => $stand['phase'],
            'fertig' => $stand['phase'] === BackupErstellung::PHASE_FERTIG,
            'dump_zeilen' => (int) ($stand['dump_zeilen'] ?? 0),
            'dateien' => (int) ($stand['blob_dateien'] ?? 0) + (int) ($stand['logo_dateien'] ?? 0),
            'dateien_gesamt' => (int) ($stand['dateien_gesamt'] ?? 0),
            'verschluesselt' => $verschluesselung === null ? 0 : (int) $verschluesselung['block'] * BlobCipher::CHUNK_BYTES,
            'zip_bytes' => (int) ($stand['zip_bytes'] ?? 0),
        ];
    }
}
