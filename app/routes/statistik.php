<?php

declare(strict_types=1);

use App\App\StatistikController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The page "Statistik" (docs/spec/09-listen-und-exporte.md section 7): the
 * member figures for every role (listen.sehen - counts like the lists),
 * the fee figures for the admin only (beitraege.verwalten, as the page
 * "Beiträge").
 */
return static function (Routen $routen): void {
    $controller = static fn(): StatistikController => StatistikController::aus($routen->dienste());

    $routen->get(Navigation::STATISTIK, Zugriff::recht(Permission::ListenSehen), static fn(Request $r): Response => $controller()->mitglieder($r));
    $routen->get(StatistikController::PFAD_BEITRAEGE, Zugriff::recht(Permission::BeitraegeVerwalten), static fn(Request $r): Response => $controller()->beitraege($r));
};
