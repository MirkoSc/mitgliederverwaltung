<?php

declare(strict_types=1);

use App\App\VerlaufController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * "Zurückholen" of a member version (docs/spec/07-mitglieder.md sections 7
 * and 9, issue #22). Both roles may; the tab itself is part of the
 * member page.
 */
return static function (Routen $routen): void {
    $routen->post(
        '/app/mitglieder/{id:[0-9]+}/verlauf/{nr:[0-9]+}/zurueckholen',
        Zugriff::recht(Permission::MitgliederBearbeiten),
        static fn(Request $r, array $p): Response => VerlaufController::aus($routen->dienste())->zurueckholen($r, $p),
    );
};
