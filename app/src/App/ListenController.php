<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
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
 * The page "Listen" (docs/spec/09-listen-und-exporte.md, 06 section 2): the
 * selection, one list with its filters, sorting and paging, and the export.
 * Every list brings its columns and rows; this controller is the same for
 * all of them.
 *
 * A list the account may not see does not exist for it: not found and not
 * allowed look the same.
 */
final readonly class ListenController
{
    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    public function uebersicht(): Response
    {
        return Response::html($this->dienste->view->render('listen/uebersicht', [
            'title' => 'Listen',
            'listen' => Listen::registry()->alle($this->dienste, $this->dienste->view->konto()),
        ], Area::App));
    }

    public function anzeigen(Request $request, string $schluessel): Response
    {
        $konto = $this->dienste->view->konto();
        $liste = $konto === null ? null : Listen::registry()->finde($this->dienste, $schluessel, $konto);
        if ($liste === null || $konto === null) {
            return $this->nichtGefunden();
        }

        $ansicht = ListenAnsicht::aus($liste, $request->query, $konto);
        $seite = $ansicht->seite($request->query['seite'] ?? null);

        return Response::html($this->dienste->view->render('listen/liste', [
            'title' => $liste->titel(),
            'listen' => Listen::registry()->alle($this->dienste, $konto),
            'ansicht' => $ansicht,
            'seite' => $seite,
            'zeilen' => $ansicht->zeilenAufSeite($seite),
            'formate' => ExportFormat::cases(),
            'pfad' => Navigation::LISTEN . '/' . $liste->schluessel(),
            'mitgliedPfad' => Navigation::MITGLIEDER,
        ], Area::App));
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
            'meldung' => 'Diese Liste gibt es nicht. Wählen Sie eine aus der Übersicht.',
            'startseite' => Navigation::LISTEN,
        ], Area::App), 404);
    }
}
