<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Service\Integritaet\CodeIntegritaet;
use App\Service\Integritaet\IntegritaetPruefungLaeuftBereits;
use App\Service\Integritaet\IntegritaetPruefungNichtMoeglich;
use App\Service\SystemCheck\CurlStatusProbe;
use App\Service\SystemCheck\SystemCheck;

/**
 * The two parts of the settings section System: the system check
 * (a fragment) and the integrity check (start and step of a chain of short
 * requests, each answering with the fragment of its current state).
 */
final readonly class SystemController
{
    private const string ZIEL = '/admin#system';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function systemcheck(Request $request): Response
    {
        $paths = $this->dienste->paths;
        $check = new SystemCheck(
            pdo: $this->dienste->pdo(),
            varDir: $paths->varDir(),
            statusProbe: new CurlStatusProbe(),
            sharedDir: $paths->sharedDir(),
        );

        return Response::html($this->dienste->view->fragment('admin/einstellungen/systemcheck', [
            'ergebnisse' => $check->all($request->ownOrigin()),
        ]));
    }

    public function integritaet(Request $request): Response
    {
        return $this->antwort($request, null);
    }

    public function starten(Request $request): Response
    {
        $pruefung = $this->pruefung();
        try {
            $pruefung->starten();
        } catch (IntegritaetPruefungNichtMoeglich | IntegritaetPruefungLaeuftBereits $e) {
            return $this->antwort($request, $e->getMessage());
        }

        return $this->antwort($request, null);
    }

    public function schritt(Request $request): Response
    {
        $pruefung = $this->pruefung();
        try {
            if ($pruefung->stand() !== null) {
                $pruefung->schritt();
            }
        } catch (IntegritaetPruefungNichtMoeglich | IntegritaetPruefungLaeuftBereits $e) {
            return $this->antwort($request, $e->getMessage());
        }

        return $this->antwort($request, null);
    }

    private function antwort(Request $request, ?string $fehler): Response
    {
        if (!$request->istHtmx()) {
            return Response::redirect(self::ZIEL);
        }
        $pruefung = $this->pruefung();
        $stand = $pruefung->stand();

        return Response::html($this->dienste->view->fragment('admin/einstellungen/integritaet', [
            'stand' => $stand,
            'laeuft' => $stand !== null && $stand['phase'] !== CodeIntegritaet::PHASE_FERTIG && $fehler === null,
            'hindernis' => $fehler ?? ($stand === null ? $pruefung->hindernis() : $pruefung->echtheitsfehler()),
            'warnung' => $pruefung->warnung(),
            'signaturHinweis' => $pruefung->signaturHinweis(),
            'arten' => [
                'geaendert' => 'Geändert',
                'fehlend' => 'Fehlt',
                'zusaetzlich' => 'Zusätzlich (nicht Teil des Releases)',
                'umgebung' => 'Umgebung (Webverzeichnis, config.php)',
            ],
        ]));
    }

    private function pruefung(): CodeIntegritaet
    {
        return $this->dienste->dienst(CodeIntegritaet::class, static function (Dienste $d): CodeIntegritaet {
            $p = $d->paths;

            return new CodeIntegritaet(
                releaseRoot: $p->releaseRoot,
                listenDatei: $p->sharedDir() . '/release_checksums.txt',
                statusDir: $p->varDir(),
                webDir: $p->webDir(),
                configDatei: $p->configFile(),
            );
        });
    }
}
