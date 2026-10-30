<?php

declare(strict_types=1);

use App\App\BeitragsjahrController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * Actions on the page "Beiträge" over more than one fee
 * (docs/spec/08-beitraege.md section 2a, issue #90): delete the fees of a
 * year, several fees paid at once. Admin only: every route declares
 * BeitraegeVerwalten, so the Vorstand gets 403 on each.
 */
return static function (Routen $routen): void {
    $zugriff = Zugriff::recht(Permission::BeitraegeVerwalten);
    $controller = static fn(): BeitragsjahrController => BeitragsjahrController::aus($routen->dienste());

    $routen->get(BeitragsjahrController::PFAD_LOESCHEN, $zugriff, static fn(Request $r): Response => $controller()->loeschenFragen($r));
    $routen->post(BeitragsjahrController::PFAD_LOESCHEN, $zugriff, static fn(Request $r): Response => $controller()->loeschen($r));
    $routen->post(BeitragsjahrController::PFAD_BEZAHLT, $zugriff, static fn(Request $r): Response => $controller()->bezahlt($r));
};
