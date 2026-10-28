<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Service\MaintenanceMode;
use App\Service\Update\UpdateService;
use App\Service\Update\UpdateState;

/**
 * The routes of the settings section Update (docs/spec/04-installation-und-
 * update.md section 3, taken over from Vereinsbelege): one request per step
 * of the chain (driven by public/js/update.js), the channel, resetting a
 * stuck state, and the manual release of the maintenance flag.
 *
 * The release is the one thing a crashed update can leave behind that locks
 * everybody out, so it has its own route and a banner on every admin page
 * (04 section 4): without it, the only way back would be FTP.
 *
 * Every route needs `admin.system` and CSRF (both declared and enforced by
 * app/routes/update.php and App\Http\CsrfSchutz) - no foreign page can start
 * a step in an administrator's browser.
 */
final readonly class UpdateController
{
    public const string ZIEL = '/admin#update';

    public function __construct(
        private Dienste $dienste,
        private UpdateService $updates,
        private MaintenanceMode $wartung,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, UpdateService::aus($dienste), MaintenanceMode::aus($dienste));
    }

    public function kanal(Request $request): Response
    {
        $this->updates->setChannel($request->feld('kanal'));
        // A half-finished chain belongs to the old channel's release.
        $this->updates->reset();
        $this->dienste->session->flash('Update-Kanal gespeichert.');

        return Response::redirect(self::ZIEL);
    }

    /**
     * One step per request (CLAUDE.md section 1: no long request).
     *
     * @param array<string, string> $params
     */
    public function schritt(Request $request, array $params): ResponseInterface
    {
        try {
            $state = match ($params['schritt'] ?? '') {
                'check' => $this->updates->check(),
                'download' => $this->updates->download(),
                'extract' => $this->updates->extract(),
                'backup' => $this->updates->backup(),
                'switch' => $this->updates->switchRelease(),
                'migrate' => $this->updates->migrate(),
                'finish' => $this->updates->finish($request->ownOrigin()),
                'rollback' => $this->updates->rollback(),
                default => null,
            };
        } catch (\Throwable $e) {
            // Only the class goes to the log; our own messages (a step
            // without a prepared update) say what to do.
            $this->dienste->logger->append('Update step failed: ' . $e::class);
            $meldung = $e instanceof \RuntimeException && !$e instanceof \PDOException
                ? $e->getMessage()
                : 'Der Schritt ist fehlgeschlagen. Bitte noch einmal versuchen.';

            return Response::json(['fehler' => $meldung], 500);
        }

        if (!$state instanceof UpdateState) {
            return Response::json(['fehler' => 'Unbekannter Schritt.'], 404);
        }

        // Only the two steps that change which release runs are worth a
        // row; the others prepare and may be repeated freely.
        $aktion = match (true) {
            $state->fehler !== null => null,
            $params['schritt'] === 'finish' && $state->fertig => AuditAktion::UpdateDurchgefuehrt,
            $params['schritt'] === 'rollback' => AuditAktion::UpdateZurueckgerollt,
            default => null,
        };
        if ($aktion !== null) {
            $this->protokollieren($aktion, $request, [
                'von' => $state->aktuelleVersion,
                'nach' => $state->zielVersion ?? '',
            ]);
        }

        return Response::json($state->toArray(), $state->fehler !== null ? 500 : 200);
    }

    public function zuruecksetzen(): Response
    {
        $this->updates->reset();
        $this->dienste->session->flash('Der Update-Stand ist zurückgesetzt.');

        return Response::redirect(self::ZIEL);
    }

    public function wartungAufheben(Request $request): Response
    {
        $this->wartung->disable();
        $this->protokollieren(AuditAktion::WartungAufgehoben, $request);
        $this->dienste->session->flash('Der Wartungsmodus ist aufgehoben – die Seite ist wieder erreichbar.');

        // A fixed target, deliberately not "back to where you came from":
        // that parameter would be an open redirect to save one click.
        return Response::redirect(self::ZIEL);
    }

    /**
     * @param array<string, scalar|null|list<scalar>> $details
     */
    private function protokollieren(AuditAktion $aktion, Request $request, array $details = []): void
    {
        try {
            $this->dienste->audit()->protokollieren($aktion, $this->dienste->session->userId(), details: $details, ip: $request->ip);
        } catch (\PDOException) {
            // A rollback of an update that failed before its migrations ran
            // may find no audit table yet. The step itself has happened;
            // failing its answer now would only hide that.
        }
    }
}
