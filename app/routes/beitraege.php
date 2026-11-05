<?php

declare(strict_types=1);

use App\App\BeitraegeController;
use App\App\StatistikController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The page "Beiträge" and the page of one annual fee
 * (docs/spec/08-beitraege.md section 2, issue #29), and the fee
 * distribution of a year (section 2b). Admin only: every
 * route declares BeitraegeVerwalten, so the Vorstand gets 403 on each.
 */
return static function (Routen $routen): void {
    $zugriff = Zugriff::recht(Permission::BeitraegeVerwalten);
    $controller = static fn(): BeitraegeController => BeitraegeController::aus($routen->dienste());
    $beitrag = Navigation::BEITRAEGE . '/{id:[0-9]+}';

    $routen->get(Navigation::BEITRAEGE, $zugriff, static fn(Request $r): Response => $controller()->seite($r));
    // Moved to the page "Statistik" (09 section 7): old bookmarks redirect.
    $routen->get(BeitraegeController::PFAD_VERTEILUNG, $zugriff, static fn(Request $r): Response => StatistikController::aus($routen->dienste())->alteVerteilung($r));
    $routen->post(Navigation::BEITRAEGE . '/erzeugen', $zugriff, static fn(Request $r): Response => $controller()->erzeugen($r));
    $routen->get($beitrag, $zugriff, static fn(Request $r, array $p): Response => $controller()->beitrag($r, $p));
    $routen->post($beitrag . '/betrag', $zugriff, static fn(Request $r, array $p): Response => $controller()->betrag($r, $p));
    $routen->post($beitrag . '/bezahlt', $zugriff, static fn(Request $r, array $p): Response => $controller()->bezahlt($r, $p));
    $routen->post($beitrag . '/ruecklastschrift', $zugriff, static fn(Request $r, array $p): Response => $controller()->ruecklastschrift($r, $p));
    $routen->post($beitrag . '/vermerk', $zugriff, static fn(Request $r, array $p): Response => $controller()->vermerk($r, $p));
};
