<?php

declare(strict_types=1);

use App\Admin\SystemController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * Settings section System (SystemAbschnitt): the system check and the
 * integrity check (docs/spec/04-installation-und-update.md sections 5, 7).
 * Admin only.
 */
return static function (Routen $routen): void {
    $admin = Zugriff::recht(Permission::AdminSystem);

    $routen->get('/admin/systemcheck', $admin, static fn(Request $r): Response => SystemController::aus($routen->dienste())->systemcheck($r));
    $routen->get('/admin/integritaet', $admin, static fn(Request $r): Response => SystemController::aus($routen->dienste())->integritaet($r));
    $routen->post('/admin/integritaet/start', $admin, static fn(Request $r): Response => SystemController::aus($routen->dienste())->starten($r));
    $routen->post('/admin/integritaet/schritt', $admin, static fn(Request $r): Response => SystemController::aus($routen->dienste())->schritt($r));
};
