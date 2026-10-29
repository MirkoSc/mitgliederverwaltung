<?php

declare(strict_types=1);

use App\Admin\UpdateController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The settings section Update (App\Admin\Einstellungen\UpdateAbschnitt,
 * docs/spec/04-installation-und-update.md sections 3 and 4): the step chain
 * (JSON, driven by public/js/update.js), the channel, resetting the state
 * and the release of the maintenance flag. Right `admin.system`.
 *
 * The step names are part of the contract between releases: after the
 * "switch" step the NEXT requests already run on the new release, so these
 * routes have to exist in every version from now on.
 */
return static function (Routen $routen): void {
    $system = Zugriff::recht(Permission::AdminSystem);

    $routen->post('/admin/update/kanal', $system, static fn(Request $r): Response => UpdateController::aus($routen->dienste())->kanal($r));
    $routen->post(
        '/admin/update/schritt/{schritt}',
        $system->alsApi(),
        static fn(Request $r, array $p): ResponseInterface => UpdateController::aus($routen->dienste())->schritt($r, $p),
    );
    $routen->post('/admin/update/zuruecksetzen', $system, static fn(Request $r): Response => UpdateController::aus($routen->dienste())->zuruecksetzen());
    $routen->post('/admin/wartung/aufheben', $system, static fn(Request $r): Response => UpdateController::aus($routen->dienste())->wartungAufheben($r));
};
