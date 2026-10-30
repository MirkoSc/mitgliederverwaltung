<?php

declare(strict_types=1);

use App\Admin\SchluesselwechselController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * Settings section System: changing the server key (issue #80,
 * docs/spec/02-sicherheit.md section 2). Admin only; the start asks for the
 * password once more.
 */
return static function (Routen $routen): void {
    $admin = Zugriff::recht(Permission::AdminSystem);

    $routen->get('/admin/schluesselwechsel', $admin, static fn(Request $r): Response => SchluesselwechselController::aus($routen->dienste())->zeigen($r));
    $routen->post('/admin/schluesselwechsel/start', $admin, static fn(Request $r): Response => SchluesselwechselController::aus($routen->dienste())->starten($r));
    $routen->post('/admin/schluesselwechsel/schritt', $admin, static fn(Request $r): Response => SchluesselwechselController::aus($routen->dienste())->schritt($r));
};
