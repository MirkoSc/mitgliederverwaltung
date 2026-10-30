<?php

declare(strict_types=1);

namespace App\App;

use App\App\Mitglied\Einbettung;
use App\App\Mitglied\GeoeffnetesMitglied;
use App\App\Mitglied\MitgliedAnsicht;
use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Listen\Liste;
use App\Listen\ListenAnsicht;
use App\Listen\Listen;
use App\Service\Export\ExportFormat;
use App\Service\Export\ListenExport;
use App\View\Area;
use App\View\Navigation;

/**
 * The page "Listen" (docs/spec/09-listen-und-exporte.md, 06 section 2): one
 * list with its filters, sorting and paging, the lists as tabs on top, a
 * person opened beside it, and the export.
 * Every list brings its columns and rows; this controller is the same for
 * all of them.
 *
 * A list the account may not see does not exist for it: not found and not
 * allowed look the same.
 */
final readonly class ListenController
{
    private const string STANDARD = 'geburtstage';
    private const string ZIEL_BEREICH = 'listen-bereich';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    /**
     * "/app/listen" opens with the birthdays (09 section 2): there is no
     * page of its own for the selection. An account that may not see that
     * list gets the first one it may see.
     */
    public function uebersicht(Request $request): Response
    {
        $konto = $this->dienste->view->konto();
        $listen = $konto === null ? [] : Listen::registry()->alle($this->dienste, $konto);
        $standard = null;
        foreach ($listen as $kandidat) {
            if ($kandidat->schluessel() === self::STANDARD) {
                $standard = $kandidat;
            }
        }
        $standard ??= $listen[0] ?? null;

        return $standard === null || $konto === null ? $this->nichtGefunden() : $this->seite($request, $standard, $konto, $listen);
    }

    public function anzeigen(Request $request, string $schluessel): Response
    {
        $konto = $this->dienste->view->konto();
        $liste = $konto === null ? null : Listen::registry()->finde($this->dienste, $schluessel, $konto);
        if ($liste === null || $konto === null) {
            return $this->nichtGefunden();
        }

        return $this->seite($request, $liste, $konto, Listen::registry()->alle($this->dienste, $konto));
    }

    /**
     * One list with the opened person beside it (the building block of
     * 06 section 5). htmx swaps the area of list and person as a whole -
     * opening a person, its tabs, sorting, turning the period or the page.
     *
     * @param list<Liste> $listen every list of the account, for the tabs
     */
    private function seite(Request $request, Liste $liste, Konto $konto, array $listen): Response
    {
        $view = $this->dienste->view;
        $ansicht = ListenAnsicht::aus($liste, $request->query, $konto);
        $seite = $ansicht->seite($request->query['seite'] ?? null);
        $pfad = Navigation::LISTEN . '/' . $liste->schluessel();

        // A person opens beside the list only for whoever may see members.
        $darfMitglied = $konto->darf(Permission::MitgliederSehen);
        $einbettung = new Einbettung($pfad, $ansicht->parameter([], $seite))->mitHtmx(self::ZIEL_BEREICH);
        $geoeffnet = $darfMitglied
            ? MitgliedAnsicht::aus($this->dienste)->oeffnen($request, $einbettung)
            : new GeoeffnetesMitglied(null, false, null, $view->fragment('mitglied/ansicht', ['mitglied' => null]));

        $daten = [
            'title' => $liste->titel(),
            'listen' => $listen,
            'ansicht' => $ansicht,
            'seite' => $seite,
            'zeilen' => $ansicht->zeilenAufSeite($seite),
            'formate' => ExportFormat::cases(),
            'pfad' => $pfad,
            'einbettung' => $einbettung,
            'geoeffnet' => $geoeffnet,
            'darfMitglied' => $darfMitglied,
            'scripts' => ['/js/anschrift.js'],
        ];

        $htmx = $request->istHtmx() && $request->header('hx-history-restore-request') !== 'true';
        if ($htmx && $request->header('hx-target') === self::ZIEL_BEREICH) {
            return Response::html($view->fragment('listen/bereich', $daten));
        }

        return Response::html($view->render('listen/liste', $daten, Area::App));
    }

    public function exportieren(Request $request, string $schluessel): ResponseInterface
    {
        $konto = $this->dienste->view->konto();
        $liste = $konto === null ? null : Listen::registry()->finde($this->dienste, $schluessel, $konto);
        if ($liste === null || $konto === null) {
            return $this->nichtGefunden();
        }

        $format = ExportFormat::tryFrom(is_string($request->post['format'] ?? null) ? $request->post['format'] : '') ?? ExportFormat::Xlsx;
        $ansicht = ListenAnsicht::aus($liste, $request->post, $konto);

        return new ListenExport($this->dienste->audit())->antwort($ansicht, $format, $konto, $request->ip);
    }

    private function nichtGefunden(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Liste nicht gefunden',
            'meldung' => 'Diese Liste gibt es nicht. Wählen Sie oben eine andere Liste.',
            'startseite' => Navigation::LISTEN,
        ], Area::App), 404);
    }
}
