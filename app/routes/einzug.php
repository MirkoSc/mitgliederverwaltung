<?php

declare(strict_types=1);

use App\App\EinzugController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The direct debit (docs/spec/08-beitraege.md sections 3 and 4, issue #30):
 * create a run, the page of one run, download its stored file, confirm or
 * discard it. Admin only: every route declares BeitraegeVerwalten, so the
 * Vorstand gets 403 on each. The compose list and the archive are part of
 * the page "Beiträge" (app/routes/beitraege.php).
 */
return static function (Routen $routen): void {
    $zugriff = Zugriff::recht(Permission::BeitraegeVerwalten);
    $controller = static fn(): EinzugController => EinzugController::aus($routen->dienste());
    $einzug = EinzugController::PFAD . '/{id:[0-9]+}';

    $routen->post(Navigation::BEITRAEGE . '/einzug', $zugriff, static fn(Request $r): Response => $controller()->erzeugen($r));
    $routen->get($einzug, $zugriff, static fn(Request $r, array $p): Response => $controller()->seite($r, $p));
    $routen->get($einzug . '/datei', $zugriff, static fn(Request $r, array $p): ResponseInterface => $controller()->datei($r, $p));
    $routen->post($einzug . '/bestaetigen', $zugriff, static fn(Request $r, array $p): Response => $controller()->bestaetigen($r, $p));
    $routen->post($einzug . '/verwerfen', $zugriff, static fn(Request $r, array $p): Response => $controller()->verwerfen($r, $p));
};
