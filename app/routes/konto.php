<?php

declare(strict_types=1);

use App\App\KontoController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The own account, from the user menu (docs/spec/06-oberflaeche.md
 * section 2): every logged-in account changes its own password, no right
 * needed.
 */
return static function (Routen $routen): void {
    $routen->get(Navigation::PASSWORT, Zugriff::angemeldet(), static fn(Request $r): Response => KontoController::aus($routen->dienste())->passwortForm($r));
    $routen->post(Navigation::PASSWORT, Zugriff::angemeldet(), static fn(Request $r): Response => KontoController::aus($routen->dienste())->passwort($r));
    $routen->post(Navigation::BARRIEREFREI, Zugriff::angemeldet(), static fn(Request $r): Response => KontoController::aus($routen->dienste())->barrierefrei($r));
    $routen->post(Navigation::WEITERARBEITEN, Zugriff::angemeldet(), static fn(Request $r): Response => KontoController::aus($routen->dienste())->weiterarbeiten($r));
};
